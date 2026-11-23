<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Jobs\AlerterDeLaMonteeDesEchecsJob;
use App\Domain\Tenancy\Jobs\AlerterDuSeuilDEchecsJob;
use App\Domain\Tenancy\Mail\AlerteEditeurMail;
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
use Illuminate\Support\Facades\Mail;
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
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
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
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
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
     * L'attaque d'un même compte depuis beaucoup d'adresses : deux cents
     * essais manqués sur un même identifiant depuis deux cents adresses IP
     * différentes, aucune n'en faisant plus d'un. Le compteur de
     * l'identifiant, toutes adresses confondues, retient l'attaque au
     * vingt et unième essai de l'heure ; la bonne réponse donnée ensuite
     * depuis une adresse neuve attend aussi ; la vraie personne, elle, se
     * connecte toujours depuis son poste connu.
     */
    public function test_deux_cents_essais_depuis_deux_cents_adresses_sont_retenus_au_vingt_et_unieme_et_le_titulaire_se_connecte_depuis_son_poste(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $vendeur = $this->membre($this->ouvrirUneSociete(['nom' => 'Ma Boutique']), RoleMagasin::Vendeur);
        $identifiant = (string) $vendeur->identifiantDeConnexion();
        $poste = $this->posteConnuDe($identifiant);
        $adresses = [];

        foreach (range(1, 200) as $rang) {
            $adresse = '198.18.'.intdiv($rang, 250).'.'.($rang % 250 + 1);
            $adresses[$adresse] = true;
            $attendu = $rang <= LimitationDesConnexions::ECHECS_PAR_SAISIE ? Connexion::MESSAGE_ECHEC : LimitationDesConnexions::message(LimitationDesConnexions::ATTENTE);
            $this->assertSame($attendu, $this->erreurHttp($this->essayerDepuis($adresse, $identifiant, 'pas-le-bon')), "essai {$rang}");
        }

        $this->assertCount(200, $adresses);
        $this->assertSame(LimitationDesConnexions::message(LimitationDesConnexions::ATTENTE), $this->erreurHttp($this->essayerDepuis('192.0.2.99', $identifiant, self::MOT_DE_PASSE_CHOISI)));
        $this->assertGuest();
        $this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI, $poste)->assertHasNoErrors();
        $this->assertAuthenticatedAs($vendeur);
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
     * Après beaucoup d'échecs, le compte attend, mais n'est jamais bloqué :
     * chaque échec à partir du vingtième de l'heure renouvelle l'attente de
     * 15 minutes, jamais plus longue, jusqu'au dernier échec que permet le
     * plafond du jour (le 24e : le 25e lance l'attente de la fin de la
     * journée, voir le test de ce plafond) ; l'attente passée, son bon mot
     * de passe l'ouvre, et son statut n'a pas changé.
     */
    public function test_aucun_compte_n_est_jamais_bloque_definitivement(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $vendeur = $this->membre($this->ouvrirUneSociete(['nom' => 'Ma Boutique']), RoleMagasin::Vendeur);
        $identifiant = (string) $vendeur->identifiantDeConnexion();
        $limitation = app(LimitationDesConnexions::class);

        foreach (range(1, LimitationDesConnexions::ECHECS_PAR_SAISIE_ET_PAR_JOUR - 1) as $rang) {
            $this->travel(1)->second();
            $limitation->echec($identifiant, '127.0.0.1', null, $vendeur, false);

            if ($rang >= LimitationDesConnexions::ECHECS_PAR_SAISIE) {
                $this->assertSame(LimitationDesConnexions::ATTENTE, $limitation->attente($identifiant, '127.0.0.1', false), "échec {$rang} : l'attente est renouvelée, jamais rallongée.");
            }
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
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
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
        $this->travelTo(self::aParis('2026-10-09 09:55:00'));
        $vendeur = $this->membre($this->ouvrirUneSociete(['nom' => 'Ma Boutique']), RoleMagasin::Vendeur);
        $identifiant = (string) $vendeur->identifiantDeConnexion();
        $poste = $this->posteConnuDe($identifiant);

        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        foreach (range(1, LimitationDesConnexions::ECHECS_DE_SUITE_PAR_POSTE - 1) as $rang) {
            $this->assertSame(Connexion::MESSAGE_ECHEC, $this->erreur($this->seConnecter($identifiant, 'pas-le-bon', $poste)), "essai {$rang}");
        }
        $this->travelTo(self::aParis('2026-10-09 10:50:00'));
        $this->assertSame(Connexion::MESSAGE_ECHEC, $this->erreur($this->seConnecter($identifiant, 'pas-le-bon', $poste)));

        $this->travelTo(self::aParis('2026-10-09 11:05:00'));
        foreach (range(1, LimitationDesConnexions::ECHECS_PAR_SAISIE_ET_ADRESSE) as $rang) {
            $this->assertSame(Connexion::MESSAGE_ECHEC, $this->erreur($this->seConnecter($identifiant, 'pas-le-bon', $poste)), "essai ignoré {$rang}");
        }
        $this->assertSame(LimitationDesConnexions::message(60), $this->erreur($this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI, $poste)));

        $this->travelTo(self::aParis('2026-10-09 11:51:00'));
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
        config(['piliqi.connexions.alerte_echecs_par_heure' => 100000, 'piliqi.connexions.alerte_plancher_par_heure' => 100000, 'piliqi.connexions.alerte_identifiants_par_adresse' => 100000]);
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

        $this->assertSame(Connexion::MESSAGE_ECHEC, $this->erreur($this->seConnecter($dernier, $dernier)));
        $this->erreur($this->seConnecter($this->identifiantInconnu(), 'pas-la-saisie'));

        $this->assertCount(1, $journaux);
        $this->assertSame(['warning', LimitationDesConnexions::MESSAGE_ALERTE_EDITEUR], array_slice($journaux[0], 0, 2));
        $this->assertStringNotContainsString($dernier, implode(' ', $journaux[0]));
        $this->assertDoesNotMatchRegularExpression('/\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/', implode(' ', $journaux[0]));
    }

    /**
     * Vingt-cinq échecs en une journée pour un même saisie, hors d'un poste
     * connu, font attendre jusqu'à la fin de la journée qui a commencé au
     * premier échec : jamais plus de vingt-cinq essais par saisie et par
     * jour, jamais un blocage pour de bon. Les échecs, étalés sur des heures
     * différentes depuis des adresses différentes, n'atteignent aucun autre
     * seuil. Un identifiant inconnu est traité de même.
     */
    public function test_vingt_cinq_echecs_par_jour_font_attendre_jusqu_a_la_fin_de_la_journee_sans_bloquer_pour_de_bon(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $vendeur = $this->membre($this->ouvrirUneSociete(['nom' => 'Ma Boutique']), RoleMagasin::Vendeur);
        $limitation = app(LimitationDesConnexions::class);

        foreach ([(string) $vendeur->identifiantDeConnexion(), $this->identifiantInconnu()] as $saisie) {
            $debut = now();

            foreach (range(0, 4) as $heure) {
                $this->travelTo($debut->copy()->addHours($heure));

                foreach (range(1, 5) as $rang) {
                    $this->assertSame(0, $limitation->attente($saisie, "198.51.100.{$rang}", false), "heure {$heure}, essai {$rang}");
                    $limitation->echec($saisie, "198.51.100.{$rang}", null, null, false);
                }
            }

            $this->assertSame(20 * 3600, $limitation->attente($saisie, '203.0.113.250', false), 'vingt-cinq échecs, à quatre heures du premier : vingt heures d\'attente');
            $this->assertSame('Trop de tentatives. Réessayez dans 20 heures.', LimitationDesConnexions::message($limitation->attente($saisie, '203.0.113.250', false)));
            $this->assertSame(0, $limitation->attente($saisie, '203.0.113.250', true), 'Un poste connu n\'attend jamais.');

            $this->travelTo($debut->copy()->addHours(23)->addMinutes(59));
            $this->assertGreaterThan(0, $limitation->attente($saisie, '203.0.113.251', false));

            $this->travelTo($debut->copy()->addHours(24)->addSecond());
            $this->assertSame(0, $limitation->attente($saisie, '203.0.113.250', false), 'La journée finie, l\'accès revient.');
        }

        $this->seConnecter((string) $vendeur->identifiantDeConnexion(), self::MOT_DE_PASSE_CHOISI)->assertHasNoErrors();
        $this->assertAuthenticatedAs($vendeur);
    }

    /**
     * Le même plafond du jour, par de vraies requêtes : le vingt-sixième
     * essai de la journée reçoit l'attente, même avec le bon mot de passe,
     * depuis une adresse neuve ; sur un poste connu, jamais.
     */
    public function test_le_plafond_du_jour_s_applique_aux_requetes_et_pas_au_poste_connu(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $vendeur = $this->membre($this->ouvrirUneSociete(['nom' => 'Ma Boutique']), RoleMagasin::Vendeur);
        $identifiant = (string) $vendeur->identifiantDeConnexion();
        $poste = $this->posteConnuDe($identifiant);
        $debut = now();

        foreach (range(0, 4) as $heure) {
            $this->travelTo($debut->copy()->addHours($heure));
            foreach (range(1, 5) as $rang) {
                $this->assertSame(Connexion::MESSAGE_ECHEC, $this->erreurHttp($this->essayerDepuis("198.51.100.{$rang}", $identifiant, 'pas-le-bon')));
            }
        }

        $this->assertSame('Trop de tentatives. Réessayez dans 20 heures.', $this->erreurHttp($this->essayerDepuis('192.0.2.77', $identifiant, self::MOT_DE_PASSE_CHOISI)));
        $this->assertGuest();
        $this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI, $poste)->assertHasNoErrors();
        $this->assertAuthenticatedAs($vendeur);
    }

    public function test_une_attente_de_plus_de_deux_heures_se_dit_en_heures(): void
    {
        $this->assertSame('Trop de tentatives. Réessayez dans 1 minute.', LimitationDesConnexions::message(1));
        $this->assertSame('Trop de tentatives. Réessayez dans 2 minutes.', LimitationDesConnexions::message(61));
        $this->assertSame('Trop de tentatives. Réessayez dans 120 minutes.', LimitationDesConnexions::message(7200));
        $this->assertSame('Trop de tentatives. Réessayez dans 3 heures.', LimitationDesConnexions::message(7201));
        $this->assertSame('Trop de tentatives. Réessayez dans 24 heures.', LimitationDesConnexions::message(86400));
    }

    /**
     * L'éditeur est prévenu, une seule fois par heure et par motif, quand
     * les échecs de toute la plateforme dans l'heure dépassent un seuil
     * absolu ; en dessous, rien. Le seuil se règle (config/piliqi.php).
     */
    public function test_l_editeur_est_prevenu_quand_les_echecs_de_la_plateforme_dans_l_heure_depassent_le_seuil_absolu(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        Queue::fake();
        config(['piliqi.connexions.alerte_echecs_par_heure' => 5, 'piliqi.connexions.alerte_plancher_par_heure' => 100000, 'piliqi.connexions.alerte_identifiants_par_adresse' => 100000]);
        $limitation = app(LimitationDesConnexions::class);

        foreach (range(1, 5) as $rang) {
            $limitation->echec((string) (50000000 + $rang), "198.51.100.{$rang}", null, null, false);
        }
        Queue::assertNothingPushed();

        foreach (range(6, 9) as $rang) {
            $limitation->echec((string) (50000000 + $rang), "198.51.100.{$rang}", null, null, false);
        }
        Queue::assertPushed(AlerterDeLaMonteeDesEchecsJob::class, 1);
        Queue::assertPushed(AlerterDeLaMonteeDesEchecsJob::class, fn (AlerterDeLaMonteeDesEchecsJob $alerte): bool => [$alerte->motif, $alerte->nombre, $alerte->seuil] === [AlerterDeLaMonteeDesEchecsJob::MOTIF_ABSOLU, 6, 5]);

        $this->travelTo(self::aParis('2026-10-09 11:00:00'));
        foreach (range(1, 6) as $rang) {
            $limitation->echec((string) (50000100 + $rang), "198.51.100.{$rang}", null, null, false);
        }
        Queue::assertPushed(AlerterDeLaMonteeDesEchecsJob::class, 2);
    }

    /**
     * Trois fois la moyenne horaire des 7 jours d'avant, avec un plancher de
     * 100 : sans historique, le seuil est le plancher ; avec une moyenne de
     * 40 par heure, il est de 120.
     */
    public function test_l_editeur_est_prevenu_quand_les_echecs_depassent_trois_fois_la_moyenne_des_sept_jours_d_avant_avec_un_plancher(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        Queue::fake();
        config(['piliqi.connexions.alerte_echecs_par_heure' => 100000, 'piliqi.connexions.alerte_identifiants_par_adresse' => 100000]);
        $limitation = app(LimitationDesConnexions::class);

        foreach (range(1, 100) as $rang) {
            $limitation->echec((string) (50000000 + $rang), '198.51.100.'.($rang % 200), null, null, false);
        }
        Queue::assertNothingPushed();
        $limitation->echec('50000999', '198.51.100.201', null, null, false);
        Queue::assertPushed(AlerterDeLaMonteeDesEchecsJob::class, fn (AlerterDeLaMonteeDesEchecsJob $alerte): bool => [$alerte->motif, $alerte->nombre, $alerte->seuil] === [AlerterDeLaMonteeDesEchecsJob::MOTIF_RELATIF, 101, 100]);
        $limitation->echec('50001000', '198.51.100.202', null, null, false);
        Queue::assertPushed(AlerterDeLaMonteeDesEchecsJob::class, 1);

        $this->travelTo(self::aParis('2026-10-20 10:00:00'));
        $debut = (int) (floor(now()->getTimestamp() / 3600) * 3600);
        foreach (range(1, LimitationDesConnexions::HEURES_DE_LA_MOYENNE) as $rang) {
            Cache::put($limitation->cle('plateforme-heure', (string) ($debut - $rang * 3600)), 40, 8 * 86400);
        }
        Cache::put($limitation->cle('plateforme-heure', (string) $debut), 119, 86400);

        $limitation->echec('50002001', '198.51.100.1', null, null, false);
        Queue::assertPushed(AlerterDeLaMonteeDesEchecsJob::class, 1);
        $limitation->echec('50002002', '198.51.100.2', null, null, false);
        Queue::assertPushed(AlerterDeLaMonteeDesEchecsJob::class, 2);
        Queue::assertPushed(AlerterDeLaMonteeDesEchecsJob::class, fn (AlerterDeLaMonteeDesEchecsJob $alerte): bool => [$alerte->motif, $alerte->nombre, $alerte->seuil] === [AlerterDeLaMonteeDesEchecsJob::MOTIF_RELATIF, 121, 120]);
    }

    /**
     * Plus de 50 identifiants différents qui échouent depuis une même
     * adresse IP dans l'heure (un même mot de passe essayé sur beaucoup de
     * comptes) préviennent l'éditeur : le même identifiant répété, ou une
     * autre adresse, ne compte pas pour cette adresse.
     */
    public function test_l_editeur_est_prevenu_quand_beaucoup_d_identifiants_differents_echouent_depuis_une_meme_adresse(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        Queue::fake();
        config(['piliqi.connexions.alerte_echecs_par_heure' => 100000, 'piliqi.connexions.alerte_plancher_par_heure' => 100000]);
        $limitation = app(LimitationDesConnexions::class);

        foreach (range(1, 40) as $rang) {
            $limitation->echec((string) (60000000 + $rang), '198.51.100.9', null, null, false);
            $limitation->echec('60000001', '198.51.100.9', null, null, false);
            $limitation->echec((string) (61000000 + $rang), '198.51.100.10', null, null, false);
        }
        Queue::assertNothingPushed();

        foreach (range(41, 51) as $rang) {
            $limitation->echec((string) (60000000 + $rang), '198.51.100.9', null, null, false);
        }
        Queue::assertPushed(AlerterDeLaMonteeDesEchecsJob::class, 1);
        Queue::assertPushed(AlerterDeLaMonteeDesEchecsJob::class, fn (AlerterDeLaMonteeDesEchecsJob $alerte): bool => [$alerte->motif, $alerte->nombre, $alerte->seuil, strlen((string) $alerte->empreinte)] === [AlerterDeLaMonteeDesEchecsJob::MOTIF_ADRESSE, 51, 50, 8]);
    }

    /**
     * Une seule alerte de ce motif par heure (cahier, « une seule alerte par
     * heure et par motif »), pas une par adresse : une attaque répartie sur
     * beaucoup d'adresses, chacune au-dessus du seuil, n'envoie qu'un
     * e-mail à l'éditeur dans l'heure, celui de la première adresse qui
     * franchit le seuil, et le message le dit ; l'heure d'après, la
     * première adresse qui le franchit déclenche de nouveau l'alerte.
     */
    public function test_une_seule_alerte_de_l_adresse_part_par_heure_meme_quand_plusieurs_adresses_depassent_le_seuil(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        Queue::fake();
        config(['piliqi.connexions.alerte_echecs_par_heure' => 100000, 'piliqi.connexions.alerte_plancher_par_heure' => 100000, 'piliqi.connexions.alerte_identifiants_par_adresse' => 5]);
        $limitation = app(LimitationDesConnexions::class);

        foreach (['198.51.100.21', '198.51.100.22', '198.51.100.23'] as $rang => $adresse) {
            foreach (range(1, 8) as $identifiant) {
                $limitation->echec((string) (80000000 + $rang * 100 + $identifiant), $adresse, null, null, false);
            }
        }

        Queue::assertPushed(AlerterDeLaMonteeDesEchecsJob::class, 1);
        Queue::assertPushed(AlerterDeLaMonteeDesEchecsJob::class, fn (AlerterDeLaMonteeDesEchecsJob $alerte): bool => [$alerte->motif, $alerte->nombre, $alerte->seuil] === [AlerterDeLaMonteeDesEchecsJob::MOTIF_ADRESSE, 6, 5]);

        $this->travelTo(self::aParis('2026-10-09 11:00:00'));

        foreach (range(1, 6) as $identifiant) {
            $limitation->echec((string) (81000000 + $identifiant), '198.51.100.22', null, null, false);
        }

        Queue::assertPushed(AlerterDeLaMonteeDesEchecsJob::class, 2);
    }

    /**
     * L'e-mail de l'éditeur ne porte que des nombres et l'heure : jamais un
     * identifiant, un mot de passe, une adresse e-mail ni une adresse IP en
     * clair.
     */
    public function test_l_alerte_de_l_editeur_ne_porte_ni_identifiant_ni_adresse(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        Mail::fake();
        config(['editeur.email' => 'editeur@piliqi.test', 'piliqi.connexions.alerte_echecs_par_heure' => 100000, 'piliqi.connexions.alerte_plancher_par_heure' => 100000, 'piliqi.connexions.alerte_identifiants_par_adresse' => 2]);
        $limitation = app(LimitationDesConnexions::class);

        foreach ([70000001, 70000002, 70000003] as $identifiant) {
            $limitation->echec((string) $identifiant, '203.0.113.45', null, null, true);
        }

        Mail::assertSent(AlerteEditeurMail::class, function (AlerteEditeurMail $courriel): bool {
            $texte = $courriel->render();

            return str_contains($texte, '3 au moment de l\'alerte')
                && str_contains($texte, '09/10/2026 à 10 h (heure de Paris)')
                && preg_match('/7000000\d|203\.0\.113\.45/', strip_tags($texte)) !== 1
                && preg_match('/Empreinte de l\'adresse : [0-9a-f]{8}\. Une seule alerte de ce motif part par heure/', $texte) === 1;
        });
        Mail::assertSent(AlerteEditeurMail::class, 1);
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
