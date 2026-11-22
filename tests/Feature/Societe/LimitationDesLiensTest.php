<?php

namespace Tests\Feature\Societe;

use App\Domain\Tenancy\Actions\ChoisirSonMotDePasseParLeLienAction;
use App\Domain\Tenancy\Notifications\LienPourChoisirSonMotDePasse;
use App\Domain\Tenancy\Services\LimitationDesLiens;
use App\Domain\Tenancy\Support\MotDePasseRedemande;
use App\Filament\App\Pages\Auth\DemandeDeLien;
use App\Filament\App\Pages\Auth\PageDuLien;
use App\Filament\Societe\Pages\AccepterLaTransmission;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Livewire\Livewire;
use ReflectionProperty;
use Tests\TestCase;

/**
 * La limitation des demandes de « Mot de passe oublié » et des essais sur
 * les pages d'un lien (voir LimitationDesLiens) : chaque seuil à l'essai
 * exact et un essai au-delà, jamais un blocage pour de bon, la même réponse
 * pour une saisie qui désigne un propriétaire et pour une qui n'en désigne
 * aucun, des clés qui ne portent ni saisie ni jeton en clair, et les cinq
 * pages d'un lien (choix du mot de passe oublié, premier mot de passe d'un
 * expert-comptable de la société, confirmation d'une adresse, transmission
 * de la propriété, invitation d'un cabinet).
 */
class LimitationDesLiensTest extends TestCase
{
    use RefreshDatabase;

    private const ADRESSE_IP_AUTRE = '203.0.113.50';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    public function test_trois_demandes_par_heure_pour_une_meme_saisie_la_quatrieme_attend_la_fin_de_l_heure(): void
    {
        $limites = app(LimitationDesLiens::class);

        foreach (range(1, LimitationDesLiens::DEMANDES_PAR_HEURE_ET_PAR_SAISIE) as $rang) {
            $this->assertSame(0, $limites->demande('patron@boutique.test', '203.0.113.1'), "demande {$rang}");
            $this->travel(60)->seconds();
        }

        $attente = $limites->demande('patron@boutique.test', '203.0.113.1');
        $this->assertSame(LimitationDesLiens::UNE_HEURE - 180, $attente);
        $this->assertSame(0, $limites->demande('autre@boutique.test', '203.0.113.1'));

        $this->travel($attente - 1)->seconds();
        $this->assertGreaterThan(0, $limites->demande('patron@boutique.test', '203.0.113.1'));
    }

    /**
     * Jamais un blocage pour de bon : la fenêtre passée, la saisie demande
     * de nouveau.
     */
    public function test_l_attente_finit_avec_la_fenetre_de_l_heure(): void
    {
        $limites = app(LimitationDesLiens::class);

        foreach (range(1, LimitationDesLiens::DEMANDES_PAR_HEURE_ET_PAR_SAISIE + 1) as $rang) {
            $limites->demande('patron@boutique.test', '203.0.113.1');
        }
        $this->assertGreaterThan(0, $limites->demande('patron@boutique.test', '203.0.113.1'));

        $this->travel(LimitationDesLiens::UNE_HEURE + 1)->seconds();

        $this->assertSame(0, $limites->demande('patron@boutique.test', '203.0.113.1'));
    }

    /**
     * Dix demandes par jour : trois par heure, espacées d'une heure, la
     * onzième de la journée attend la fin des vingt-quatre heures de la
     * première.
     */
    public function test_dix_demandes_par_jour_pour_une_meme_saisie_la_onzieme_attend_la_fin_du_jour(): void
    {
        $limites = app(LimitationDesLiens::class);
        $debut = now();

        foreach ([3, 3, 3, 1] as $heure => $demandes) {
            $this->travelTo($debut->copy()->addHours($heure));

            foreach (range(1, $demandes) as $rang) {
                $this->assertSame(0, $limites->demande('patron@boutique.test', '203.0.113.1'), "heure {$heure}, demande {$rang}");
            }
        }

        $this->travelTo($debut->copy()->addHours(4));
        $attente = $limites->demande('patron@boutique.test', '203.0.113.1');
        $this->assertSame(LimitationDesLiens::UN_JOUR - 4 * 3600, $attente);

        $this->travelTo($debut->copy()->addSeconds(LimitationDesLiens::UN_JOUR + 1));
        $this->assertSame(0, $limites->demande('patron@boutique.test', '203.0.113.1'));
    }

    /**
     * Dix demandes par heure depuis une même adresse IP, quelles que soient
     * les saisies : la onzième attend, une autre adresse non.
     */
    public function test_dix_demandes_par_heure_depuis_une_meme_adresse_la_onzieme_attend(): void
    {
        $limites = app(LimitationDesLiens::class);

        foreach (range(1, LimitationDesLiens::DEMANDES_PAR_HEURE_ET_PAR_ADRESSE) as $rang) {
            $this->assertSame(0, $limites->demande("saisie{$rang}@boutique.test", '203.0.113.1'), "demande {$rang}");
        }

        $this->assertGreaterThan(0, $limites->demande('saisie11@boutique.test', '203.0.113.1'));
        $this->assertSame(0, $limites->demande('saisie12@boutique.test', self::ADRESSE_IP_AUTRE));

        $this->travel(LimitationDesLiens::UNE_HEURE + 1)->seconds();
        $this->assertSame(0, $limites->demande('saisie13@boutique.test', '203.0.113.1'));
    }

    public function test_dix_jetons_inconnus_par_heure_font_attendre_l_adresse_jusqu_a_la_fin_de_l_heure(): void
    {
        $limites = app(LimitationDesLiens::class);

        foreach (range(1, LimitationDesLiens::JETONS_INVALIDES_PAR_HEURE - 1) as $rang) {
            $limites->jetonInvalide('203.0.113.1');
            $this->assertSame(0, $limites->attenteDeLAdresse('203.0.113.1'), "jeton {$rang}");
        }

        $limites->jetonInvalide('203.0.113.1');
        $this->assertSame(LimitationDesLiens::UNE_HEURE, $limites->attenteDeLAdresse('203.0.113.1'));
        $this->assertSame(0, $limites->attenteDeLAdresse(self::ADRESSE_IP_AUTRE));

        $this->travel(LimitationDesLiens::UNE_HEURE + 1)->seconds();
        $this->assertSame(0, $limites->attenteDeLAdresse('203.0.113.1'));
    }

    public function test_cinq_mots_de_passe_refuses_sur_un_lien_le_font_attendre_quinze_minutes_sans_toucher_aux_autres_liens(): void
    {
        $limites = app(LimitationDesLiens::class);
        $jeton = Str::random(64);

        foreach (range(1, LimitationDesLiens::MOTS_DE_PASSE_REFUSES_PAR_LIEN - 1) as $rang) {
            $limites->motDePasseRefuse($jeton);
            $this->assertSame(0, $limites->attenteDuLien($jeton), "refus {$rang}");
        }

        $limites->motDePasseRefuse($jeton);
        $this->assertSame(LimitationDesLiens::ATTENTE_D_UN_LIEN, $limites->attenteDuLien($jeton));
        $this->assertSame(0, $limites->attenteDuLien(Str::random(64)));

        $this->travel(LimitationDesLiens::ATTENTE_D_UN_LIEN + 1)->seconds();
        $this->assertSame(0, $limites->attenteDuLien($jeton));
        foreach (range(1, LimitationDesLiens::MOTS_DE_PASSE_REFUSES_PAR_LIEN - 1) as $rang) {
            $limites->motDePasseRefuse($jeton);
        }
        $this->assertSame(0, $limites->attenteDuLien($jeton), 'le compte repart de zéro après l\'attente');
    }

    public function test_un_mot_de_passe_accepte_efface_les_refus_et_l_attente_du_lien(): void
    {
        $limites = app(LimitationDesLiens::class);
        $jeton = Str::random(64);

        foreach (range(1, LimitationDesLiens::MOTS_DE_PASSE_REFUSES_PAR_LIEN) as $rang) {
            $limites->motDePasseRefuse($jeton);
        }
        $this->assertGreaterThan(0, $limites->attenteDuLien($jeton));

        $limites->motDePasseAccepte($jeton);

        $this->assertSame(0, $limites->attenteDuLien($jeton));
    }

    public function test_les_messages_d_attente_disent_les_minutes_entieres(): void
    {
        $this->assertSame('Trop de tentatives. Réessayez dans 1 minute.', LimitationDesLiens::message(1));
        $this->assertSame('Trop de tentatives. Réessayez dans 15 minutes.', LimitationDesLiens::message(900));
        $this->assertSame('Trop de tentatives. Réessayez dans 16 minutes.', LimitationDesLiens::message(901));
    }

    /**
     * Ni la saisie d'une demande, ni un jeton n'apparaissent dans une clé
     * du cache : ce sont des HMAC dérivés d'APP_KEY.
     */
    public function test_aucune_cle_du_cache_ne_porte_une_saisie_ou_un_jeton_en_clair(): void
    {
        $limites = app(LimitationDesLiens::class);
        $jeton = Str::random(64);
        $limites->demande('patron@boutique.test', '203.0.113.1');
        $limites->motDePasseRefuse($jeton);
        $limites->jetonInvalide('203.0.113.1');

        $this->assertStringNotContainsString($jeton, $limites->cle('refus', $jeton));
        $this->assertStringNotContainsString(hash('sha256', $jeton), $limites->cle('refus', $jeton));
        $magasin = Cache::store()->getStore();
        $cles = implode("\n", array_keys((new ReflectionProperty($magasin::class, 'storage'))->getValue($magasin)));
        $this->assertStringContainsString('lien-', $cles);

        foreach (['patron@boutique.test', '203.0.113.1', $jeton, hash('sha256', $jeton), hash('sha256', 'patron@boutique.test')] as $secret) {
            $this->assertStringNotContainsString($secret, $cles);
        }
    }

    /**
     * La demande de « Mot de passe oublié » d'un propriétaire : trois par
     * heure, la quatrième est refusée en français, sans envoi.
     */
    public function test_la_demande_de_mot_de_passe_oublie_est_limitee_a_trois_par_heure_et_par_saisie(): void
    {
        Notification::fake();
        $proprietaire = $this->proprietaire();

        $this->demander('patron@boutique.test', LimitationDesLiens::DEMANDES_PAR_HEURE_ET_PAR_SAISIE);
        Notification::assertSentToTimes($proprietaire, LienPourChoisirSonMotDePasse::class, 3);

        Livewire::test(DemandeDeLien::class)
            ->fillForm(['email' => 'patron@boutique.test'])
            ->call('request')
            ->assertNotified(LimitationDesLiens::message(LimitationDesLiens::UNE_HEURE - 3 * 61));
        Notification::assertSentToTimes($proprietaire, LienPourChoisirSonMotDePasse::class, 3);
    }

    /**
     * Aucune énumération : la saisie d'un propriétaire, celle d'un compte
     * qui n'en est pas un et celle qui ne désigne personne reçoivent les
     * mêmes réponses, au même rang, et la même attente à la quatrième.
     */
    public function test_la_meme_reponse_et_la_meme_attente_que_la_saisie_designe_un_proprietaire_ou_non(): void
    {
        Notification::fake();
        $this->proprietaire();
        $reponses = [];

        foreach (['patron@boutique.test', 'personne@boutique.test', '12345678'] as $jour => $saisie) {
            $this->travelTo(self::aParis('2026-10-09 10:00:00')->addHours(24 * $jour));
            $reponses[$saisie] = [];

            foreach (range(1, LimitationDesLiens::DEMANDES_PAR_HEURE_ET_PAR_SAISIE + 1) as $rang) {
                $page = Livewire::test(DemandeDeLien::class)->fillForm(['email' => $saisie])->call('request');
                $reponses[$saisie][] = $rang <= LimitationDesLiens::DEMANDES_PAR_HEURE_ET_PAR_SAISIE ? DemandeDeLien::REPONSE : LimitationDesLiens::message(LimitationDesLiens::UNE_HEURE - 3 * 61);
                $page->assertNotified($reponses[$saisie][$rang - 1]);
                $this->travel(61)->seconds();
            }
        }

        $this->assertCount(1, array_unique(array_map(json_encode(...), $reponses)));
    }

    /**
     * Le propriétaire qui tape son identifiant, avec des espaces ou des
     * tirets, compte sur le même compteur que celui qui le tape d'un trait.
     */
    public function test_l_identifiant_tape_de_plusieurs_facons_compte_sur_le_meme_compteur(): void
    {
        Notification::fake();
        $this->proprietaire();
        $identifiant = (string) User::query()->where('email', 'patron@boutique.test')->sole()->identifiantDeConnexion();

        foreach ([$identifiant, substr($identifiant, 0, 4).' '.substr($identifiant, 4), substr($identifiant, 0, 4).'-'.substr($identifiant, 4)] as $ecriture) {
            Livewire::test(DemandeDeLien::class)->fillForm(['email' => $ecriture])->call('request')->assertNotified(DemandeDeLien::REPONSE);
            $this->travel(61)->seconds();
        }

        Livewire::test(DemandeDeLien::class)->fillForm(['email' => ' '.$identifiant.' '])->call('request')->assertNotified(LimitationDesLiens::message(LimitationDesLiens::UNE_HEURE - 3 * 61));
        Notification::assertSentToTimes(User::query()->where('email', 'patron@boutique.test')->sole(), LienPourChoisirSonMotDePasse::class, 3);
    }

    /**
     * Dix demandes par heure depuis une même adresse, sur dix saisies
     * différentes : la onzième attend.
     */
    public function test_dix_demandes_de_mot_de_passe_oublie_par_heure_depuis_une_meme_adresse_la_onzieme_attend(): void
    {
        Notification::fake();

        foreach (range(1, LimitationDesLiens::DEMANDES_PAR_HEURE_ET_PAR_ADRESSE) as $rang) {
            Livewire::test(DemandeDeLien::class)->fillForm(['email' => "saisie{$rang}@boutique.test"])->call('request')->assertNotified(DemandeDeLien::REPONSE);
            $this->travel(61)->seconds();
        }

        Livewire::test(DemandeDeLien::class)
            ->fillForm(['email' => 'saisie11@boutique.test'])
            ->call('request')
            ->assertNotified(LimitationDesLiens::message(LimitationDesLiens::UNE_HEURE - 10 * 61));
    }

    /**
     * Un seul lien vaut à la fois : le nouveau rend caduc le précédent.
     */
    public function test_un_nouveau_lien_de_mot_de_passe_oublie_rend_caduc_le_precedent(): void
    {
        Notification::fake();
        $proprietaire = $this->proprietaire();

        $this->demander('patron@boutique.test', 2);

        $jetons = Notification::sent($proprietaire, LienPourChoisirSonMotDePasse::class)
            ->map(fn (LienPourChoisirSonMotDePasse $lien): string => Str::between($lien->url, 'token=', '&'))
            ->values();
        $this->assertCount(2, $jetons);
        $this->assertNotSame($jetons[0], $jetons[1]);

        Livewire::test(PageDuLien::class, ['email' => 'patron@boutique.test', 'token' => $jetons[0]])->assertSee(ChoisirSonMotDePasseParLeLienAction::MESSAGE_LIEN_INVALIDE);
        Livewire::test(PageDuLien::class, ['email' => 'patron@boutique.test', 'token' => $jetons[1]])->assertDontSee(ChoisirSonMotDePasseParLeLienAction::MESSAGE_LIEN_INVALIDE);
    }

    /**
     * Page du lien « Mot de passe oublié » : dix jetons inconnus en une
     * heure depuis une adresse la font attendre ; la page le dit, sans
     * rien dire d'aucun lien.
     */
    public function test_la_page_du_lien_de_mot_de_passe_oublie_attend_apres_dix_jetons_inconnus(): void
    {
        $this->proprietaire();
        $jeton = Password::broker()->createToken(User::query()->where('email', 'patron@boutique.test')->sole());

        foreach (range(1, LimitationDesLiens::JETONS_INVALIDES_PAR_HEURE - 1) as $rang) {
            Livewire::test(PageDuLien::class, ['email' => 'patron@boutique.test', 'token' => Str::random(40)])->assertSee(ChoisirSonMotDePasseParLeLienAction::MESSAGE_LIEN_INVALIDE);
        }

        Livewire::test(PageDuLien::class, ['email' => 'patron@boutique.test', 'token' => Str::random(40)])->assertSee(LimitationDesLiens::message(LimitationDesLiens::UNE_HEURE));

        Livewire::test(PageDuLien::class, ['email' => 'patron@boutique.test', 'token' => $jeton])
            ->assertSee(LimitationDesLiens::message(LimitationDesLiens::UNE_HEURE))
            ->assertDontSee(ChoisirSonMotDePasseParLeLienAction::MESSAGE_LIEN_INVALIDE)
            ->set('data.password', 'un-mot-de-passe-solide-et-nouveau')
            ->set('data.passwordConfirmation', 'un-mot-de-passe-solide-et-nouveau')
            ->call('choisir')
            ->assertNotified(LimitationDesLiens::message(LimitationDesLiens::UNE_HEURE));
        $this->assertFalse(Hash::check('un-mot-de-passe-solide-et-nouveau', User::query()->where('email', 'patron@boutique.test')->sole()->password));

        $this->travel(LimitationDesLiens::UNE_HEURE + 1)->seconds();
        $nouveau = Password::broker()->createToken(User::query()->where('email', 'patron@boutique.test')->sole());

        Livewire::test(PageDuLien::class, ['email' => 'patron@boutique.test', 'token' => $nouveau])
            ->assertDontSee(LimitationDesLiens::message(LimitationDesLiens::UNE_HEURE))
            ->set('data.password', 'un-mot-de-passe-solide-et-nouveau')
            ->set('data.passwordConfirmation', 'un-mot-de-passe-solide-et-nouveau')
            ->call('choisir')
            ->assertHasNoFormErrors();
        $this->assertTrue(Hash::check('un-mot-de-passe-solide-et-nouveau', User::query()->where('email', 'patron@boutique.test')->sole()->password));
    }

    /**
     * Page du lien « Mot de passe oublié » : cinq mots de passe refusés sur
     * le même lien le font attendre quinze minutes, même devant le bon mot
     * de passe ; le lien reste valable, et l'attente passée il sert.
     */
    public function test_cinq_mots_de_passe_refuses_sur_le_lien_de_mot_de_passe_oublie_le_font_attendre_quinze_minutes_sans_l_annuler(): void
    {
        $proprietaire = $this->proprietaire();
        $jeton = Password::broker()->createToken($proprietaire);
        $page = Livewire::test(PageDuLien::class, ['email' => 'patron@boutique.test', 'token' => $jeton]);

        foreach (range(1, LimitationDesLiens::MOTS_DE_PASSE_REFUSES_PAR_LIEN) as $rang) {
            $page->set('data.password', 'court')->set('data.passwordConfirmation', 'court')->call('choisir')->assertHasFormErrors(['password']);
        }

        $page->set('data.password', 'un-mot-de-passe-solide-et-nouveau')
            ->set('data.passwordConfirmation', 'un-mot-de-passe-solide-et-nouveau')
            ->call('choisir')
            ->assertNotified(LimitationDesLiens::message(LimitationDesLiens::ATTENTE_D_UN_LIEN));
        $this->assertTrue(Hash::check('mot-de-passe-actuel', $proprietaire->refresh()->password));

        $this->travel(LimitationDesLiens::ATTENTE_D_UN_LIEN + 1)->seconds();
        $page->call('choisir')->assertHasNoFormErrors()->assertRedirect(Filament::getLoginUrl());
        $this->assertTrue(Hash::check('un-mot-de-passe-solide-et-nouveau', $proprietaire->refresh()->password));
    }

    /**
     * Page du lien d'un expert-comptable de la société (HTTP) : dix liens
     * inconnus ou expirés en une heure depuis une adresse la font attendre,
     * pour tous les liens ; une autre adresse, non ; l'heure passée, oui.
     */
    public function test_dix_liens_inconnus_en_une_heure_font_attendre_l_adresse_sur_toutes_les_pages_de_lien(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $this->declarerSonExpertComptable($magasin);
        $valable = $this->jetonDuDernierLienDExpertComptable();

        foreach (range(1, LimitationDesLiens::JETONS_INVALIDES_PAR_HEURE) as $rang) {
            $this->get(route('experts-comptables.lien.afficher', ['jeton' => $this->jetonInconnu()]))->assertStatus(410);
        }

        $attente = LimitationDesLiens::message(LimitationDesLiens::UNE_HEURE);
        $this->get(route('experts-comptables.lien.afficher', ['jeton' => $this->jetonInconnu()]))
            ->assertStatus(429)->assertSee($attente)->assertHeader('Retry-After', (string) LimitationDesLiens::UNE_HEURE)->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get(route('experts-comptables.lien.afficher', ['jeton' => $valable]))->assertStatus(429)->assertSee($attente);
        $this->get(route('cabinets.invitation.afficher', ['jeton' => $this->jetonInconnu()]))->assertStatus(429)->assertSee($attente);
        $this->get(route('profil.adresse.afficher', ['jeton' => $this->jetonInconnu()]))->assertStatus(429)->assertSee($attente);
        $this->post(route('experts-comptables.lien.choisir', ['jeton' => $valable]), ['password' => self::MOT_DE_PASSE_CHOISI, 'password_confirmation' => self::MOT_DE_PASSE_CHOISI])->assertStatus(429);
        $this->assertGuest();

        $this->withServerVariables(['REMOTE_ADDR' => self::ADRESSE_IP_AUTRE])->get(route('experts-comptables.lien.afficher', ['jeton' => $valable]))->assertOk();

        $this->travel(LimitationDesLiens::UNE_HEURE + 1)->seconds();
        $this->get(route('experts-comptables.lien.afficher', ['jeton' => $valable]))->assertOk();
    }

    /**
     * Les trois pages HTTP comptent chacune leurs liens inconnus : neuf
     * liens répartis sur les pages ne font pas attendre, le dixième, si.
     */
    public function test_les_liens_inconnus_des_pages_se_comptent_ensemble_par_adresse(): void
    {
        foreach (range(1, 4) as $rang) {
            $this->get(route('experts-comptables.lien.afficher', ['jeton' => $this->jetonInconnu()]))->assertStatus(410);
        }
        foreach (range(1, 3) as $rang) {
            $this->get(route('cabinets.invitation.afficher', ['jeton' => $this->jetonInconnu()]))->assertStatus(410);
        }
        foreach (range(1, 2) as $rang) {
            $this->get(route('profil.adresse.afficher', ['jeton' => $this->jetonInconnu()]))->assertStatus(410);
        }
        $this->assertSame(0, app(LimitationDesLiens::class)->attenteDeLAdresse('127.0.0.1'));

        $this->get(route('cabinets.invitation.afficher', ['jeton' => $this->jetonInconnu()]))->assertStatus(410);

        $this->assertGreaterThan(0, app(LimitationDesLiens::class)->attenteDeLAdresse('127.0.0.1'));
        $this->get(route('profil.adresse.afficher', ['jeton' => $this->jetonInconnu()]))->assertStatus(429);
    }

    /**
     * Page du lien d'un expert-comptable (HTTP) : cinq mots de passe
     * refusés sur ce lien le font attendre quinze minutes ; le lien reste
     * valable, il sert l'attente passée.
     */
    public function test_cinq_mots_de_passe_refuses_sur_le_lien_d_un_expert_comptable_le_font_attendre_quinze_minutes(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $compte = $this->declarerSonExpertComptable($magasin);
        $jeton = $this->jetonDuDernierLienDExpertComptable();

        foreach (range(1, LimitationDesLiens::MOTS_DE_PASSE_REFUSES_PAR_LIEN) as $rang) {
            $this->post(route('experts-comptables.lien.choisir', ['jeton' => $jeton]), ['password' => 'court', 'password_confirmation' => 'court'])->assertOk();
        }

        $this->post(route('experts-comptables.lien.choisir', ['jeton' => $jeton]), ['password' => self::MOT_DE_PASSE_CHOISI, 'password_confirmation' => self::MOT_DE_PASSE_CHOISI])
            ->assertStatus(429)
            ->assertSee(LimitationDesLiens::message(LimitationDesLiens::ATTENTE_D_UN_LIEN))
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertGuest();
        $this->get(route('experts-comptables.lien.afficher', ['jeton' => $jeton]))->assertOk();

        $this->travel(LimitationDesLiens::ATTENTE_D_UN_LIEN + 1)->seconds();
        $this->post(route('experts-comptables.lien.choisir', ['jeton' => $jeton]), ['password' => self::MOT_DE_PASSE_CHOISI, 'password_confirmation' => self::MOT_DE_PASSE_CHOISI])->assertRedirect();
        $this->assertAuthenticatedAs($compte);
    }

    /**
     * Invitation d'un cabinet (HTTP) : cinq mots de passe refusés sur ce
     * lien le font attendre quinze minutes, sans créer de société ; le
     * lien reste valable.
     */
    public function test_cinq_mots_de_passe_refuses_sur_une_invitation_de_cabinet_la_font_attendre_quinze_minutes(): void
    {
        $cabinet = $this->ouvrirUnCabinet();
        $jeton = $this->inviterUneEntreprise($cabinet, SocieteFactory::sirenFictif(), 'dirigeant@entreprise.test');
        $champs = ['type_activite' => 'commerce', 'choix_definitif' => '1'];

        foreach (range(1, LimitationDesLiens::MOTS_DE_PASSE_REFUSES_PAR_LIEN) as $rang) {
            $this->post(route('cabinets.invitation.accepter', ['jeton' => $jeton]), ['password' => 'court', 'password_confirmation' => 'court', ...$champs])->assertOk();
        }

        $this->post(route('cabinets.invitation.accepter', ['jeton' => $jeton]), ['password' => self::MOT_DE_PASSE_CHOISI, 'password_confirmation' => self::MOT_DE_PASSE_CHOISI, ...$champs])
            ->assertOk()
            ->assertSee(LimitationDesLiens::message(LimitationDesLiens::ATTENTE_D_UN_LIEN));
        $this->assertGuest();
        $this->assertFalse(User::query()->where('email', 'dirigeant@entreprise.test')->exists());

        $this->travel(LimitationDesLiens::ATTENTE_D_UN_LIEN + 1)->seconds();
        $this->post(route('cabinets.invitation.accepter', ['jeton' => $jeton]), ['password' => self::MOT_DE_PASSE_CHOISI, 'password_confirmation' => self::MOT_DE_PASSE_CHOISI, ...$champs])->assertRedirect();
        $this->assertTrue(User::query()->where('email', 'dirigeant@entreprise.test')->exists());
    }

    /**
     * Page de la transmission de la propriété : cinq mots de passe refusés
     * sur ce lien le font attendre quinze minutes ; la propriété ne passe
     * pas, le lien reste valable.
     */
    public function test_cinq_mots_de_passe_refuses_sur_le_lien_de_transmission_le_font_attendre_quinze_minutes(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurPretAReprendre($magasin);
        $jeton = $this->demanderLaTransmission($proprietaire, $administrateur);
        $this->actingAs($administrateur);
        Filament::setCurrentPanel(Filament::getPanel('societe'));
        $page = Livewire::test(AccepterLaTransmission::class, ['jeton' => $jeton]);

        foreach (range(1, LimitationDesLiens::MOTS_DE_PASSE_REFUSES_PAR_LIEN) as $rang) {
            $page->fillForm(['mot_de_passe' => 'pas-le-bon'])->call('accepter')->assertNotified(MotDePasseRedemande::MESSAGE);
        }

        $page->fillForm(['mot_de_passe' => self::MOT_DE_PASSE_CHOISI])->call('accepter')->assertNotified(LimitationDesLiens::message(LimitationDesLiens::ATTENTE_D_UN_LIEN));
        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));

        $this->travel(LimitationDesLiens::ATTENTE_D_UN_LIEN + 1)->seconds();
        $page->fillForm(['mot_de_passe' => self::MOT_DE_PASSE_CHOISI])->call('accepter')->assertRedirect();
        $this->assertTrue($this->proprietaireDe($magasin)->is($administrateur));
    }

    /**
     * Page de la transmission : dix liens inconnus depuis une adresse la
     * font attendre aussi.
     */
    public function test_la_page_de_transmission_compte_ses_liens_inconnus_et_attend_a_la_dixieme(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->actingAs($this->administrateurPretAReprendre($magasin));
        Filament::setCurrentPanel(Filament::getPanel('societe'));

        foreach (range(1, LimitationDesLiens::JETONS_INVALIDES_PAR_HEURE) as $rang) {
            Livewire::test(AccepterLaTransmission::class, ['jeton' => $this->jetonInconnu()]);
        }

        $this->assertSame(LimitationDesLiens::UNE_HEURE, app(LimitationDesLiens::class)->attenteDeLAdresse('127.0.0.1'));
        Livewire::test(AccepterLaTransmission::class, ['jeton' => $this->jetonInconnu()])
            ->call('accepter')
            ->assertNotified(LimitationDesLiens::message(LimitationDesLiens::UNE_HEURE));
    }

    /**
     * Les demandes de « Mot de passe oublié » : $fois demandes de cette
     * saisie, espacées de 61 secondes (le plafond de deux par minute de la
     * page).
     */
    private function demander(string $saisie, int $fois): void
    {
        foreach (range(1, $fois) as $rang) {
            Livewire::test(DemandeDeLien::class)->fillForm(['email' => $saisie])->call('request')->assertNotified(DemandeDeLien::REPONSE);
            $this->travel(61)->seconds();
        }
    }

    /**
     * Un jeton de lien de la bonne forme (64 caractères) qu'aucun lien ne
     * porte.
     */
    private function jetonInconnu(): string
    {
        return Str::random(64);
    }

    /**
     * Le propriétaire d'une société qui a un magasin, patron@boutique.test.
     */
    private function proprietaire(): User
    {
        $proprietaire = User::factory()->create(['email' => 'patron@boutique.test', 'password' => 'mot-de-passe-actuel']);
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);

        return $proprietaire->refresh();
    }
}
