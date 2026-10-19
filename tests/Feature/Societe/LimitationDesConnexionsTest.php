<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Jobs\AlerterDuSeuilDEchecsJob;
use App\Domain\Tenancy\Models\Identifiant;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\LimitationDesConnexions;
use App\Domain\Tenancy\Services\PostesConnus;
use App\Domain\Tenancy\Services\PostesReconnus;
use App\Filament\App\Pages\Auth\Connexion;
use Filament\Facades\Filament;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use ReflectionProperty;
use Tests\TestCase;

/**
 * La limitation des essais de connexion (voir LimitationDesConnexions) :
 * seuls les échecs comptent, par saisie et adresse IP, par saisie et par
 * adresse IP, avec des attentes et jamais de blocage définitif ; le poste
 * connu d'un compte n'attend jamais, sauf après dix échecs de suite ; les
 * clés des compteurs ne révèlent aucune saisie ; les alertes partent en
 * file, sans identifiant.
 */
class LimitationDesConnexionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * L'instantané de l'écran de connexion, lu une fois par test pour les
     * vraies requêtes Livewire (voir essayerDepuis()).
     */
    private ?string $instantane = null;

    public function test_trop_d_echecs_pour_un_identifiant_et_une_adresse_ip_font_attendre_de_plus_en_plus(): void
    {
        $vendeur = $this->membre($this->ouvrirUneSociete(['nom' => 'Ma Boutique']), RoleMagasin::Vendeur);
        $identifiant = (string) $vendeur->identifiantDeConnexion();

        $this->echouer($identifiant, LimitationDesConnexions::ECHECS_PAR_SAISIE_ET_ADRESSE);
        $this->assertSame(LimitationDesConnexions::message(60), $this->erreur($this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI)));

        foreach ([120 => 'Trop de tentatives. Réessayez dans 2 minutes.', 240 => 'Trop de tentatives. Réessayez dans 4 minutes.'] as $attente => $message) {
            $this->travel(intdiv($attente, 2) + 1)->seconds();
            $this->assertSame(Connexion::MESSAGE_ECHEC, $this->erreur($this->seConnecter($identifiant, 'pas-le-bon')));
            $this->assertSame($message, $this->erreur($this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI)));
        }
        $this->assertGuest();
    }

    /**
     * Qui respecte chaque attente et recommence voit l'attente monter
     * jusqu'à 15 minutes et y rester : la progression se garde tant que les
     * échecs se suivent, au-delà des 15 minutes du premier ; elle ne
     * s'oublie que 15 minutes après la fin de la dernière attente sans
     * nouvel échec.
     */
    public function test_l_attente_croissante_se_garde_tant_que_les_echecs_se_suivent_jusqu_a_quinze_minutes(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $vendeur = $this->membre($this->ouvrirUneSociete(['nom' => 'Ma Boutique']), RoleMagasin::Vendeur);
        $identifiant = (string) $vendeur->identifiantDeConnexion();

        $this->echouer($identifiant, LimitationDesConnexions::ECHECS_PAR_SAISIE_ET_ADRESSE);
        $precedente = 60;
        foreach ([120, 240, 480, 900, 900] as $attente) {
            $this->travel($precedente + 1)->seconds();
            $this->assertSame(Connexion::MESSAGE_ECHEC, $this->erreur($this->seConnecter($identifiant, 'pas-le-bon')));
            $this->assertSame(LimitationDesConnexions::message($attente), $this->erreur($this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI)), "après {$precedente} s");
            $precedente = $attente;
        }

        $this->travel(900 + LimitationDesConnexions::FENETRE_PAR_SAISIE_ET_ADRESSE + 1)->seconds();
        $this->assertSame(Connexion::MESSAGE_ECHEC, $this->erreur($this->seConnecter($identifiant, 'pas-le-bon')));
        $this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI)->assertHasNoErrors();
        $this->assertAuthenticatedAs($vendeur);
    }

    /**
     * Vingt échecs en une heure pour un même identifiant, depuis vingt
     * adresses IP, font attendre cet identifiant depuis toute adresse.
     */
    public function test_trop_d_echecs_pour_un_identifiant_le_font_attendre_depuis_toute_adresse_ip(): void
    {
        $vendeur = $this->membre($this->ouvrirUneSociete(['nom' => 'Ma Boutique']), RoleMagasin::Vendeur);
        $identifiant = (string) $vendeur->identifiantDeConnexion();

        foreach (range(1, LimitationDesConnexions::ECHECS_PAR_SAISIE) as $rang) {
            $this->assertSame(Connexion::MESSAGE_ECHEC, $this->erreurHttp($this->essayerDepuis("198.51.100.{$rang}", $identifiant, 'pas-le-bon')), "essai {$rang}");
        }

        $this->assertSame(LimitationDesConnexions::message(LimitationDesConnexions::ATTENTE), $this->erreurHttp($this->essayerDepuis('192.0.2.200', $identifiant, self::MOT_DE_PASSE_CHOISI)));
        $this->assertNull($vendeur->refresh()->derniere_connexion_le);
    }

    /**
     * Trente échecs en une heure depuis une même adresse IP, sur des
     * identifiants tous différents, font attendre cette adresse quel que
     * soit l'identifiant.
     */
    public function test_trop_d_echecs_depuis_une_adresse_ip_la_font_attendre_quel_que_soit_l_identifiant(): void
    {
        $vendeur = $this->membre($this->ouvrirUneSociete(['nom' => 'Ma Boutique']), RoleMagasin::Vendeur);

        foreach (range(1, LimitationDesConnexions::ECHECS_PAR_ADRESSE) as $rang) {
            $this->assertSame(Connexion::MESSAGE_ECHEC, $this->erreur($this->seConnecter($this->identifiantInconnu(), 'pas-le-bon')), "essai {$rang}");
        }

        $this->assertSame(LimitationDesConnexions::message(LimitationDesConnexions::ATTENTE), $this->erreur($this->seConnecter((string) $vendeur->identifiantDeConnexion(), self::MOT_DE_PASSE_CHOISI)));
        $this->assertSame(Connexion::MESSAGE_ECHEC, $this->erreurHttp($this->essayerDepuis('192.0.2.7', (string) $vendeur->identifiantDeConnexion(), 'pas-le-bon')));
    }

    public function test_les_compteurs_s_appliquent_aussi_a_un_identifiant_inexistant(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $inconnu = $this->identifiantInconnu();

        $this->echouer($inconnu, LimitationDesConnexions::ECHECS_PAR_SAISIE_ET_ADRESSE);

        $this->assertSame(LimitationDesConnexions::message(60), $this->erreur($this->seConnecter($inconnu, 'pas-le-bon')));
    }

    /**
     * Les compteurs se rangent sous la saisie normalisée : écrire
     * l'identifiant avec des espaces, des tirets ou des points, ou
     * l'adresse dans une autre casse, compte pour la même saisie.
     */
    public function test_varier_l_ecriture_d_un_identifiant_ou_la_casse_d_une_adresse_ne_contourne_pas_les_compteurs(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $identifiant = (string) $this->membre($magasin, RoleMagasin::Vendeur)->identifiantDeConnexion();
        $adresse = $this->proprietaireDe($magasin)->email;

        foreach ([$identifiant, substr($identifiant, 0, 4).' '.substr($identifiant, 4), substr($identifiant, 0, 4).'-'.substr($identifiant, 4), implode('.', str_split($identifiant, 2)), ' '.$identifiant.' '] as $ecriture) {
            $this->assertSame(Connexion::MESSAGE_ECHEC, $this->erreur($this->seConnecter($ecriture, 'pas-le-bon')));
        }
        $this->assertSame(LimitationDesConnexions::message(60), $this->erreur($this->seConnecter(implode(' ', str_split($identifiant, 2)), self::MOT_DE_PASSE_CHOISI)));

        foreach ([$adresse, strtoupper($adresse), ' '.ucfirst($adresse), strtoupper($adresse).' ', $adresse] as $ecriture) {
            $this->assertSame(Connexion::MESSAGE_ECHEC, $this->erreur($this->seConnecter($ecriture, 'pas-le-bon')));
        }
        $this->assertSame(LimitationDesConnexions::message(60), $this->erreur($this->seConnecter(strtoupper($adresse), 'password')));
    }

    /**
     * Les connexions réussies ne comptent pas : les vendeurs qui se
     * connectent les uns après les autres depuis le même poste du magasin
     * n'attendent jamais.
     */
    public function test_les_connexions_reussies_d_un_meme_poste_ne_bloquent_pas_les_vendeurs_suivants(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeurs = [$this->membre($magasin, RoleMagasin::Vendeur), $this->membre($magasin, RoleMagasin::Vendeur), $this->membre($magasin, RoleMagasin::Observateur)];

        foreach (range(1, LimitationDesConnexions::ECHECS_PAR_ADRESSE + 5) as $rang) {
            $vendeur = $vendeurs[$rang % 3];
            $this->seConnecter((string) $vendeur->identifiantDeConnexion(), self::MOT_DE_PASSE_CHOISI)->assertHasNoErrors();
            $this->assertAuthenticatedAs($vendeur);
        }
    }

    /**
     * Après beaucoup d'échecs, le compte attend, mais n'est jamais bloqué
     * : l'attente passée, son bon mot de passe l'ouvre, et son statut n'a
     * pas changé.
     */
    public function test_aucun_compte_n_est_jamais_bloque_definitivement(): void
    {
        $vendeur = $this->membre($this->ouvrirUneSociete(['nom' => 'Ma Boutique']), RoleMagasin::Vendeur);
        $identifiant = (string) $vendeur->identifiantDeConnexion();
        foreach (range(1, LimitationDesConnexions::ECHECS_PAR_SAISIE + 5) as $rang) {
            $this->travel(1)->second();
            app(LimitationDesConnexions::class)->echec($identifiant, '127.0.0.1', null, $vendeur, false);
        }
        $this->assertSame(LimitationDesConnexions::message(LimitationDesConnexions::ATTENTE), $this->erreur($this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI)));

        $this->travel(LimitationDesConnexions::ATTENTE + 1)->seconds();

        $this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI)->assertHasNoErrors();
        $this->assertAuthenticatedAs($vendeur);
        $this->assertSame(StatutCompte::Actif, $vendeur->refresh()->statut);
    }

    /**
     * Qui connaît l'identifiant d'un collègue et multiplie les échecs le
     * fait attendre, lui, jamais le titulaire sur son poste connu.
     */
    public function test_des_echecs_depuis_ailleurs_ne_bloquent_pas_le_titulaire_sur_son_poste(): void
    {
        $vendeur = $this->membre($this->ouvrirUneSociete(['nom' => 'Ma Boutique']), RoleMagasin::Vendeur);
        $identifiant = (string) $vendeur->identifiantDeConnexion();
        $poste = $this->posteConnuDe($identifiant);

        $this->echouer($identifiant, LimitationDesConnexions::ECHECS_PAR_SAISIE_ET_ADRESSE);
        foreach (range(1, LimitationDesConnexions::ECHECS_PAR_SAISIE) as $rang) {
            $this->erreurHttp($this->essayerDepuis("198.51.100.{$rang}", $identifiant, 'pas-le-bon'));
        }
        $this->assertSame(LimitationDesConnexions::message(LimitationDesConnexions::ATTENTE), $this->erreur($this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI)));

        $this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI, $poste)->assertHasNoErrors();
        $this->assertAuthenticatedAs($vendeur);
    }

    /**
     * Trente échecs depuis l'adresse commune du magasin font attendre
     * cette adresse, mais pas les postes connus de ses vendeurs.
     */
    public function test_trente_echecs_depuis_l_adresse_du_magasin_ne_bloquent_pas_les_postes_connus(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $connu = $this->membre($magasin, RoleMagasin::Vendeur);
        $inconnuDuPoste = $this->membre($magasin, RoleMagasin::Vendeur);
        $poste = $this->posteConnuDe((string) $connu->identifiantDeConnexion());

        foreach (range(1, LimitationDesConnexions::ECHECS_PAR_ADRESSE) as $rang) {
            $this->erreur($this->seConnecter($this->identifiantInconnu(), 'pas-le-bon'));
        }

        $this->assertSame(LimitationDesConnexions::message(LimitationDesConnexions::ATTENTE), $this->erreur($this->seConnecter((string) $inconnuDuPoste->identifiantDeConnexion(), self::MOT_DE_PASSE_CHOISI)));
        $this->seConnecter((string) $connu->identifiantDeConnexion(), self::MOT_DE_PASSE_CHOISI, $poste)->assertHasNoErrors();
        $this->assertAuthenticatedAs($connu);
    }

    /**
     * Dix échecs de suite avec le témoin d'un poste connu le font ignorer
     * une heure : les échecs suivants comptent comme ailleurs, et font
     * attendre même le bon mot de passe ; l'heure passée, le poste vaut de
     * nouveau.
     */
    public function test_dix_echecs_de_suite_avec_un_temoin_de_poste_le_font_ignorer_une_heure(): void
    {
        $vendeur = $this->membre($this->ouvrirUneSociete(['nom' => 'Ma Boutique']), RoleMagasin::Vendeur);
        $identifiant = (string) $vendeur->identifiantDeConnexion();
        $poste = $this->posteConnuDe($identifiant);

        foreach (range(1, LimitationDesConnexions::ECHECS_DE_SUITE_PAR_POSTE + LimitationDesConnexions::ECHECS_PAR_SAISIE_ET_ADRESSE) as $rang) {
            $this->assertSame(Connexion::MESSAGE_ECHEC, $this->erreur($this->seConnecter($identifiant, 'pas-le-bon', $poste)), "essai {$rang}");
        }
        $this->assertSame(LimitationDesConnexions::message(60), $this->erreur($this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI, $poste)));

        $this->travel(LimitationDesConnexions::UNE_HEURE + 1)->seconds();

        $this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI, $poste)->assertHasNoErrors();
        $this->assertAuthenticatedAs($vendeur);
    }

    /**
     * Le poste est ignoré pendant l'heure qui suit son 10e échec, même
     * quand ses échecs sont étalés : 9 échecs à 10 h, le 10e à 10 h 50, il
     * est encore ignoré à 11 h 05 (ses échecs comptent comme ailleurs et
     * font attendre), et vaut de nouveau à 11 h 51.
     */
    public function test_un_poste_connu_reste_ignore_l_heure_qui_suit_son_dixieme_echec_meme_etale(): void
    {
        $this->travelTo('2026-10-09 09:55:00');
        $vendeur = $this->membre($this->ouvrirUneSociete(['nom' => 'Ma Boutique']), RoleMagasin::Vendeur);
        $identifiant = (string) $vendeur->identifiantDeConnexion();
        $poste = $this->posteConnuDe($identifiant);

        $this->travelTo('2026-10-09 10:00:00');
        foreach (range(1, LimitationDesConnexions::ECHECS_DE_SUITE_PAR_POSTE - 1) as $rang) {
            $this->assertSame(Connexion::MESSAGE_ECHEC, $this->erreur($this->seConnecter($identifiant, 'pas-le-bon', $poste)), "essai {$rang}");
        }
        $this->travelTo('2026-10-09 10:50:00');
        $this->assertSame(Connexion::MESSAGE_ECHEC, $this->erreur($this->seConnecter($identifiant, 'pas-le-bon', $poste)));

        $this->travelTo('2026-10-09 11:05:00');
        foreach (range(1, LimitationDesConnexions::ECHECS_PAR_SAISIE_ET_ADRESSE) as $rang) {
            $this->assertSame(Connexion::MESSAGE_ECHEC, $this->erreur($this->seConnecter($identifiant, 'pas-le-bon', $poste)), "essai ignoré {$rang}");
        }
        $this->assertSame(LimitationDesConnexions::message(60), $this->erreur($this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI, $poste)));

        $this->travelTo('2026-10-09 11:51:00');
        $this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI, $poste)->assertHasNoErrors();
        $this->assertAuthenticatedAs($vendeur);
    }

    /**
     * Au-delà de vingt échecs en une heure sur l'identifiant d'un compte de
     * sa société, le propriétaire en est prévenu, une fois par heure, par
     * un avis qui nomme le compte, jamais son identifiant.
     */
    public function test_le_proprietaire_est_prevenu_quand_un_identifiant_de_sa_societe_depasse_le_seuil_d_echecs(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul']);
        $identifiant = (string) $vendeur->identifiantDeConnexion();

        foreach (range(1, LimitationDesConnexions::ECHECS_PAR_SAISIE + 5) as $rang) {
            $this->erreurHttp($this->essayerDepuis("198.51.100.{$rang}", $identifiant, 'pas-le-bon'));
        }

        $avis = Notification::sent($this->proprietaireDe($magasin), AvisDeLEquipe::class)->filter(fn (AvisDeLEquipe $avis): bool => $avis->titre === 'Essais de connexion manqués');
        $this->assertCount(1, $avis);
        $this->assertStringContainsString('Le compte de Paul a reçu plus de 20 essais', $avis->first()->texte);
        $this->assertStringNotContainsString($identifiant, $avis->first()->texte);
        Notification::assertNotSentTo($vendeur, AvisDeLEquipe::class);
    }

    /**
     * L'alerte du seuil part en file : rien n'est envoyé pendant la
     * requête de connexion, dont le délai trahirait sinon que
     * l'identifiant existe.
     */
    public function test_l_alerte_du_seuil_part_en_file(): void
    {
        Queue::fake();
        Notification::fake();
        $vendeur = $this->membre($this->ouvrirUneSociete(['nom' => 'Ma Boutique']), RoleMagasin::Vendeur);

        foreach (range(1, LimitationDesConnexions::ECHECS_PAR_SAISIE + 1) as $rang) {
            $this->erreurHttp($this->essayerDepuis("198.51.100.{$rang}", (string) $vendeur->identifiantDeConnexion(), 'pas-le-bon'));
        }

        Queue::assertPushed(AlerterDuSeuilDEchecsJob::class, fn (AlerterDuSeuilDEchecsJob $tache): bool => $tache->compteId === (int) $vendeur->id);
        Queue::assertPushed(AlerterDuSeuilDEchecsJob::class, 1);
        Notification::assertNothingSent();
    }

    /**
     * La clé d'un compteur n'est ni la saisie, ni son empreinte sans clé :
     * c'est un HMAC dérivé d'APP_KEY ; aucune clé du cache ne contient la
     * saisie ni son SHA-256.
     */
    public function test_la_cle_d_une_saisie_n_est_pas_son_empreinte_sans_cle(): void
    {
        $vendeur = $this->membre($this->ouvrirUneSociete(['nom' => 'Ma Boutique']), RoleMagasin::Vendeur);
        $identifiant = (string) $vendeur->identifiantDeConnexion();
        $limitation = app(LimitationDesConnexions::class);

        $cle = $limitation->cle('paire', $identifiant, '127.0.0.1');
        $this->assertStringNotContainsString($identifiant, $cle);
        $this->assertStringNotContainsString(hash('sha256', $identifiant), $cle);
        $this->assertSame('connexion-paire:'.hash_hmac('sha256', $identifiant, hash_hkdf('sha256', (string) base64_decode(substr((string) config('app.key'), 7), true), 32, 'limitation-des-connexions')).':'.hash_hmac('sha256', '127.0.0.1', hash_hkdf('sha256', (string) base64_decode(substr((string) config('app.key'), 7), true), 32, 'limitation-des-connexions')), $cle);
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        $this->assertNotSame($cle, $limitation->cle('paire', $identifiant, '127.0.0.1'));

        $this->echouer($identifiant, 3);
        $magasin = Cache::store()->getStore();
        $this->assertInstanceOf(ArrayStore::class, $magasin);
        $cles = implode("\n", array_keys((new ReflectionProperty(ArrayStore::class, 'storage'))->getValue($magasin)));
        $this->assertStringContainsString('connexion-', $cles);
        $this->assertStringNotContainsString($identifiant, $cles);
        $this->assertStringNotContainsString(hash('sha256', $identifiant), $cles);
    }

    /**
     * Au-delà de deux cents essais manqués en une heure, sur toute la
     * plateforme, dont le mot de passe tapé est la saisie elle-même,
     * l'éditeur est alerté dans le journal de l'application, une fois,
     * sans identifiant ni adresse IP.
     */
    public function test_les_essais_ou_le_mot_de_passe_egale_l_identifiant_alertent_l_editeur_sans_identifiant_dans_le_journal(): void
    {
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $journaux = [];
        Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$journaux): void {
            $journaux[] = [$message->level, $message->message, json_encode($message->context)];
        });
        $limitation = app(LimitationDesConnexions::class);
        foreach (range(1, LimitationDesConnexions::ECHECS_EGAUX_A_LA_SAISIE_PAR_HEURE) as $rang) {
            $saisie = (string) (40000000 + $rang);
            $limitation->echec($saisie, '203.0.113.'.($rang % 250), null, null, true);
        }
        $this->assertSame([], $journaux);
        $dernier = $this->identifiantInconnu();

        $this->assertSame(Connexion::MESSAGE_ECHEC.' '.Connexion::MESSAGE_POSTE, $this->erreur($this->seConnecter($dernier, $dernier)));
        $this->erreur($this->seConnecter($this->identifiantInconnu(), 'pas-la-saisie'));

        $this->assertCount(1, $journaux);
        $this->assertSame(['warning', LimitationDesConnexions::MESSAGE_ALERTE_EDITEUR], array_slice($journaux[0], 0, 2));
        $this->assertStringNotContainsString($dernier, implode(' ', $journaux[0]));
        $this->assertDoesNotMatchRegularExpression('/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', implode(' ', $journaux[0]));
    }

    /**
     * Une connexion au panneau des magasins par l'écran, sans session
     * ouverte, avec ces témoins de poste ; depuis 127.0.0.1.
     *
     * @param  array<string, string>  $postes
     */
    private function seConnecter(string $saisie, string $motDePasse, array $postes = []): Testable
    {
        $this->flushSession();
        auth()->logout();
        Filament::setCurrentPanel(Filament::getPanel('app'));

        return Livewire::withCookies([PostesReconnus::TEMOIN => '', PostesConnus::TEMOIN => '', ...$postes])
            ->test(Connexion::class)
            ->fillForm(['login' => $saisie, 'password' => $motDePasse])
            ->call('authenticate');
    }

    /**
     * Autant d'échecs par l'écran, depuis 127.0.0.1.
     */
    private function echouer(string $saisie, int $fois): void
    {
        foreach (range(1, $fois) as $rang) {
            $this->assertSame(Connexion::MESSAGE_ECHEC, $this->erreur($this->seConnecter($saisie, 'pas-le-bon')), "échec {$rang}");
        }
    }

    private function erreur(Testable $page): ?string
    {
        return $page->errors()->first('data.login') ?: null;
    }

    /**
     * Le témoin de poste connu que reçoit le navigateur après une connexion
     * réussie de ce compte, puis la session fermée.
     *
     * @return array<string, string>
     */
    private function posteConnuDe(string $identifiant): array
    {
        $this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI)->assertHasNoErrors();
        $temoin = (string) Cookie::queued(PostesConnus::TEMOIN)?->getValue();
        $this->assertNotSame('', $temoin);
        auth()->logout();

        return [PostesConnus::TEMOIN => $temoin];
    }

    /**
     * Un essai par une vraie requête Livewire, depuis cette adresse IP.
     */
    private function essayerDepuis(string $adresse, string $saisie, string $motDePasse): TestResponse
    {
        $this->flushSession();
        auth()->logout();
        $this->instantane ??= $this->instantaneLivewire($this->get('/app/login')->assertOk()->getContent(), 'connexion');

        return $this->withServerVariables(['REMOTE_ADDR' => $adresse])->requeteLivewire($this->instantane, ['data.login' => $saisie, 'data.password' => $motDePasse], 'authenticate');
    }

    private function erreurHttp(TestResponse $reponse): ?string
    {
        $instantane = json_decode((string) $reponse->json('components.0.snapshot'), true);

        return $instantane['memo']['errors']['data.login'][0] ?? null;
    }

    /**
     * Un identifiant de la bonne forme qu'aucun compte n'a.
     */
    private function identifiantInconnu(): string
    {
        do {
            $identifiant = (string) random_int(30_000_000, 39_999_999);
        } while (Identifiant::withoutGlobalScopes()->where('identifiant', $identifiant)->exists());

        return $identifiant;
    }
}
