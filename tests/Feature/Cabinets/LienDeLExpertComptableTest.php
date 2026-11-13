<?php

namespace Tests\Feature\Cabinets;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\ChoisirLeMotDePasseDeLExpertComptableAction;
use App\Domain\Cabinets\Actions\CorrigerLAdresseDeLExpertComptableAction;
use App\Domain\Cabinets\Actions\DeclarerSonExpertComptableAction;
use App\Domain\Cabinets\Actions\MettreFinAuLienAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Exceptions\LienDeLExpertComptableInvalideException;
use App\Domain\Cabinets\Mail\LienDuMotDePasseDeLExpertComptableMail;
use App\Domain\Cabinets\Models\CompteExpertComptable;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\EnvoiDesLiensDesExpertsComptables;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\ConfirmerNouvelleAdresseAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\ModeDAccesRedonne;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Mail\AdresseChangeeMail;
use App\Domain\Tenancy\Mail\ConfirmationNouvelleAdresseMail;
use App\Domain\Tenancy\Models\EntreeAudit;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Notifications\LienPourChoisirSonMotDePasse;
use App\Domain\Tenancy\Services\DebitDesEmails;
use App\Domain\Tenancy\Services\PostesConnus;
use App\Domain\Tenancy\Services\PostesReconnus;
use App\Domain\Tenancy\Services\SuiviDesConnexions;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\MotDePasseRedemande;
use App\Filament\App\Pages\Auth\Connexion;
use App\Filament\App\Pages\Auth\DemandeDeLien;
use App\Filament\App\Pages\Auth\ModifierProfil;
use App\Filament\Societe\Resources\MembreResource;
use App\Filament\Societe\Resources\MembreResource\Pages\FicheDuMembre;
use App\Filament\Societe\Resources\MembreResource\Pages\ListMembres;
use App\Integrations\Courriel\CourrielNonParti;
use App\Integrations\Sirene\Contracts\RepertoireSirene;
use App\Integrations\Sirene\FauxRepertoireSirene;
use App\Models\User;
use Database\Factories\SocieteFactory;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use LogicException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

/**
 * Le lien de 7 jours où l'expert-comptable de la société choisit son mot
 * de passe (décision de l'éditeur du 01/11/2026, choix 1) : il part après
 * la transaction, à l'adresse saisie par le propriétaire, sans identifiant
 * ni mot de passe ; l'ouvrir n'écrit rien ; choisir son mot de passe le
 * consomme, connecte la personne et lui montre son identifiant ; un lien
 * qui ne vaut plus rend une même page neutre. La personne ne se connecte
 * ensuite qu'avec son identifiant et son mot de passe.
 */
class LienDeLExpertComptableTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_lien_part_apres_la_transaction_a_l_adresse_saisie_sans_identifiant_ni_mot_de_passe(): void
    {
        $this->travelTo(self::aParis('2026-11-03 10:00:00'));
        $magasin = $this->ouvrirUneSociete();
        app(RepertoireSirene::class)->nommer(FauxRepertoireSirene::SIREN_CABINET, 'CABINET PETIT ET ASSOCIES');

        $compte = $this->declarerSonExpertComptable($magasin);

        Mail::assertSent(LienDuMotDePasseDeLExpertComptableMail::class, function (LienDuMotDePasseDeLExpertComptableMail $mail) use ($compte): bool {
            $rendu = $mail->render();

            return $mail->hasTo('marc.petit@petit-associes.test')
                && str_contains($rendu, 'cabinet CABINET PETIT ET ASSOCIES')
                && str_contains($rendu, '10/11/2026 à 10:00')
                && ! str_contains($rendu, (string) $compte->identifiantDeConnexion())
                && ! str_contains($rendu, 'password');
        });
    }

    public function test_le_lien_vaut_sept_jours_a_la_meme_heure_de_paris_meme_au_changement_d_heure(): void
    {
        $this->travelTo(self::aParis('2027-03-27 10:00:00'));
        $magasin = $this->ouvrirUneSociete();
        $compte = $this->declarerSonExpertComptable($magasin);

        $ligne = CompteExpertComptable::query()->withoutGlobalScopes()->where('user_id', $compte->id)->firstOrFail();
        $this->assertMemeInstant(self::aParis('2027-04-03 10:00:00'), $ligne->jeton_expire_le);

        $this->travelTo(self::aParis('2027-04-03 10:00:01'));
        $this->get(route('experts-comptables.lien.afficher', ['jeton' => $this->jetonDuDernierLienDExpertComptable()]))->assertStatus(410);
    }

    public function test_ouvrir_le_lien_n_ecrit_rien_et_ne_montre_ni_l_identifiant_ni_l_adresse_ni_le_proprietaire(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $compte = $this->declarerSonExpertComptable($magasin);
        $avant = $this->instantaneDesTables('users', 'comptes_expert_comptable', 'liens_cabinet');

        $page = $this->get(route('experts-comptables.lien.afficher', ['jeton' => $this->jetonDuDernierLienDExpertComptable()]));

        $page->assertOk()->assertSee('Bienvenue Marc Petit.')->assertSee('SIREN '.$magasin->societe->siren);
        $page->assertDontSee((string) $compte->identifiantDeConnexion())->assertDontSee('marc.petit@petit-associes.test')->assertDontSee($this->proprietaireDe($magasin)->name);
        $this->assertSame($avant, $this->instantaneDesTables('users', 'comptes_expert_comptable', 'liens_cabinet'));
        $this->assertGuest();
    }

    public function test_choisir_son_mot_de_passe_consomme_le_lien_connecte_la_personne_et_montre_son_identifiant(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $compte = $this->declarerSonExpertComptable($magasin);
        $jeton = $this->jetonDuDernierLienDExpertComptable();

        $this->post(route('experts-comptables.lien.choisir', ['jeton' => $jeton]), ['password' => self::MOT_DE_PASSE_CHOISI, 'password_confirmation' => self::MOT_DE_PASSE_CHOISI])
            ->assertRedirect()
            ->assertHeader('Referrer-Policy', 'no-referrer');

        $this->assertAuthenticatedAs($compte);
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE_CHOISI, $compte->fresh()->password));
        $ligne = CompteExpertComptable::query()->withoutGlobalScopes()->where('user_id', $compte->id)->firstOrFail();
        $this->assertNull($ligne->jeton_expire_le);
        $this->assertNotNull($ligne->mot_de_passe_choisi_le);
        $this->assertStringContainsString((string) $compte->identifiantDeConnexion(), json_encode(session('filament.notifications'), JSON_THROW_ON_ERROR));
        $this->assertNotNull($compte->fresh()->premiere_connexion_le);

        auth()->logout();
        $this->get(route('experts-comptables.lien.afficher', ['jeton' => $jeton]))->assertStatus(410)->assertSee(LienDeLExpertComptableInvalideException::MESSAGE);
    }

    public function test_sans_magasin_reel_le_mot_de_passe_choisi_mene_a_l_accueil_du_panneau_des_magasins(): void
    {
        $societe = $this->inscrireUneSociete();
        $this->saisirLeCodeDuCourrier($societe)->forceFill(['password' => 'password'])->save();
        $compte = $this->declarerSonExpertComptable($societe->fresh());

        $this->post(route('experts-comptables.lien.choisir', ['jeton' => $this->jetonDuDernierLienDExpertComptable()]), ['password' => self::MOT_DE_PASSE_CHOISI, 'password_confirmation' => self::MOT_DE_PASSE_CHOISI])
            ->assertRedirect(route('filament.app.tenant'));

        $this->assertAuthenticatedAs($compte);
        $this->get(route('filament.app.tenant'))->assertRedirect(route('filament.app.aucun-magasin'));
    }

    public function test_le_mot_de_passe_choisi_suit_la_regle_et_ne_contient_pas_l_identifiant(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $compte = $this->declarerSonExpertComptable($magasin);
        $jeton = $this->jetonDuDernierLienDExpertComptable();
        $identifiant = (string) $compte->identifiantDeConnexion();

        foreach (['court', 'aaaaaaaaaa', '12345678', "x{$identifiant}y"] as $mauvais) {
            $this->post(route('experts-comptables.lien.choisir', ['jeton' => $jeton]), ['password' => $mauvais, 'password_confirmation' => $mauvais])->assertOk();
            $this->assertGuest();
        }

        $this->post(route('experts-comptables.lien.choisir', ['jeton' => $jeton]), ['password' => self::MOT_DE_PASSE_CHOISI, 'password_confirmation' => 'autre-mot-de-passe'])->assertOk();
        $this->assertGuest();
        $this->assertNotNull(CompteExpertComptable::query()->withoutGlobalScopes()->where('user_id', $compte->id)->firstOrFail()->jeton_expire_le);
    }

    public function test_un_lien_expire_deja_servi_remplace_ou_inconnu_rend_la_meme_page_neutre(): void
    {
        $this->travelTo(self::aParis('2026-11-03 10:00:00'));
        $magasin = $this->ouvrirUneSociete();
        $this->declarerSonExpertComptable($magasin);
        $jeton = $this->jetonDuDernierLienDExpertComptable();

        $this->get(route('experts-comptables.lien.afficher', ['jeton' => str_repeat('a', 64)]))->assertStatus(410)->assertSee(LienDeLExpertComptableInvalideException::MESSAGE);
        $this->post(route('experts-comptables.lien.choisir', ['jeton' => str_repeat('a', 64)]), ['password' => self::MOT_DE_PASSE_CHOISI, 'password_confirmation' => self::MOT_DE_PASSE_CHOISI])->assertStatus(410);

        $this->travelTo(self::aParis('2026-11-10 10:00:01'));
        $this->get(route('experts-comptables.lien.afficher', ['jeton' => $jeton]))->assertStatus(410)->assertSee(LienDeLExpertComptableInvalideException::MESSAGE);
        $this->post(route('experts-comptables.lien.choisir', ['jeton' => $jeton]), ['password' => self::MOT_DE_PASSE_CHOISI, 'password_confirmation' => self::MOT_DE_PASSE_CHOISI])->assertStatus(410);
        $this->assertGuest();
    }

    public function test_la_page_du_lien_ne_transmet_pas_son_adresse(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $this->declarerSonExpertComptable($magasin);

        $this->get(route('experts-comptables.lien.afficher', ['jeton' => $this->jetonDuDernierLienDExpertComptable()]))
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('<meta name="referrer" content="no-referrer">', false);
        $this->get(route('experts-comptables.lien.afficher', ['jeton' => str_repeat('b', 64)]))->assertHeader('Referrer-Policy', 'no-referrer');
    }

    public function test_le_champ_du_mot_de_passe_de_la_page_du_lien_porte_l_asterisque_des_champs_obligatoires(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $this->declarerSonExpertComptable($magasin);

        $html = $this->get(route('experts-comptables.lien.afficher', ['jeton' => $this->jetonDuDernierLienDExpertComptable()]))->getContent();

        $this->assertAsterisquesDesChamps((string) $html, ['Votre mot de passe', 'Confirmez votre mot de passe']);
    }

    public function test_l_expert_comptable_ne_se_connecte_jamais_avec_son_adresse_ni_avec_son_identifiant_pour_mot_de_passe(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $compte = $this->expertComptable($magasin);
        $identifiant = (string) $compte->identifiantDeConnexion();

        $this->assertSame([Connexion::MESSAGE_ECHEC], $this->seConnecter('marc.petit@petit-associes.test', self::MOT_DE_PASSE_CHOISI)->errors()->get('data.login'));
        $this->assertGuest();
        $this->assertStringStartsWith(Connexion::MESSAGE_ECHEC, (string) ($this->seConnecter($identifiant, $identifiant)->errors()->get('data.login')[0] ?? ''));
        $this->assertGuest();

        $this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI)->assertHasNoErrors();
        $this->assertAuthenticatedAs($compte);
    }

    public function test_une_panne_de_la_messagerie_efface_le_lien_et_previent_le_proprietaire(): void
    {
        $magasin = $this->ouvrirUneSociete();
        Mail::extend('en-panne', fn (): AbstractTransport => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('Connection to "sandbox.smtp.mailtrap.io:2525" timed out.');
            }

            public function __toString(): string
            {
                return 'en-panne';
            }
        });
        config(['mail.mailers.en-panne' => ['transport' => 'en-panne'], 'mail.default' => 'en-panne']);
        app(TenantContext::class)->clear();

        $this->assertThrows(fn () => app(DeclarerSonExpertComptableAction::class)->handle($this->proprietaireDe($magasin), $magasin->societe, [
            'siren' => FauxRepertoireSirene::SIREN_CABINET,
            'name' => 'Marc Petit',
            'email' => 'marc.petit@petit-associes.test',
            'email_confirmation' => 'marc.petit@petit-associes.test',
            'autorisations' => PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet($magasin->societe->type_activite)),
        ], 'password'), CourrielNonParti::class);

        app(TenantContext::class)->clear();
        $compte = User::query()->where('role_societe', 'expert_comptable')->firstOrFail();
        $this->assertNull(CompteExpertComptable::query()->withoutGlobalScopes()->where('user_id', $compte->id)->firstOrFail()->jeton_expire_le);
        $this->assertSame(1, $this->proprietaireDe($magasin)->notifications()->where('type', AvisDeLEquipe::class)->where('data->title', 'E-mail non envoyé')->count());
        $this->assertSame(1, DB::table('liens_cabinet')->where('etat', 'declare')->count());
    }

    public function test_un_nouveau_lien_coupe_l_ancien_mot_de_passe_l_ancien_lien_et_les_sessions(): void
    {
        $this->travelTo(self::aParis('2026-12-15 16:05:00'));
        $magasin = $this->ouvrirUneSociete();
        $compte = $this->expertComptable($magasin);
        $proprietaire = $this->proprietaireDe($magasin);
        DB::table('sessions')->insert(['id' => 'session-de-marc', 'user_id' => $compte->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);

        $this->assertTrue(app(RedonnerLAccesAction::class)->parUnNouveauLien($proprietaire, $compte, (string) Str::uuid()));

        $compte->refresh();
        $this->assertFalse(Hash::check(self::MOT_DE_PASSE_CHOISI, $compte->password));
        $this->assertFalse($compte->mot_de_passe_est_l_identifiant);
        $this->assertSame(ModeDAccesRedonne::Lien, $compte->acces_redonne_mode);
        $this->assertSame(0, DB::table('sessions')->where('user_id', $compte->id)->count());
        $ligne = CompteExpertComptable::query()->withoutGlobalScopes()->where('user_id', $compte->id)->firstOrFail();
        $this->assertMemeInstant(self::aParis('2026-12-22 16:05:00'), $ligne->jeton_expire_le);
        Mail::assertSent(LienDuMotDePasseDeLExpertComptableMail::class, fn (LienDuMotDePasseDeLExpertComptableMail $mail): bool => str_contains($mail->render(), "Votre accès a été redonné par {$proprietaire->name}"));
        $this->assertSame(ModeDAccesRedonne::Lien->value, app(TenantContext::class)->runAsSociete($magasin->societe, fn () => EntreeAudit::query()->where('action', ActionAuditee::AccesRedonne->value)->latest('id')->firstOrFail()->nouvelles_valeurs['mode'] ?? null));

        $nouveau = $this->jetonDuDernierLienDExpertComptable();
        $this->post(route('experts-comptables.lien.choisir', ['jeton' => $nouveau]), ['password' => 'Autre-Mot-2026!', 'password_confirmation' => 'Autre-Mot-2026!'])->assertRedirect();
        $this->assertAuthenticatedAs($compte);
        $this->assertTrue(Hash::check('Autre-Mot-2026!', $compte->fresh()->password));
    }

    public function test_l_acces_redonne_en_presence_marche_et_en_absence_est_refuse_pour_un_expert_comptable(): void
    {
        $this->travelTo(self::aParis('2026-11-05 09:00:00'));
        $magasin = $this->ouvrirUneSociete();
        $compte = $this->declarerSonExpertComptable($magasin);
        $proprietaire = $this->proprietaireDe($magasin);

        $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->handle($proprietaire, $compte, false), LogicException::class, RedonnerLAccesAction::MESSAGE_EXPERT_COMPTABLE);
        $this->assertNotNull(CompteExpertComptable::query()->withoutGlobalScopes()->where('user_id', $compte->id)->firstOrFail()->jeton_expire_le);

        $this->assertTrue(app(RedonnerLAccesAction::class)->handle($proprietaire, $compte, true, self::MOT_DE_PASSE_CHOISI, (string) Str::uuid()));

        $ligne = CompteExpertComptable::query()->withoutGlobalScopes()->where('user_id', $compte->id)->firstOrFail();
        $this->assertNull($ligne->jeton_expire_le);
        $this->assertMemeInstant(self::aParis('2026-11-05 09:00:00'), $ligne->mot_de_passe_choisi_le);
        $this->assertSame(ModeDAccesRedonne::Presence, $compte->fresh()->acces_redonne_mode);
        $this->assertTrue(Hash::check(self::MOT_DE_PASSE_CHOISI, $compte->fresh()->password));
    }

    public function test_un_renvoi_repete_avec_le_meme_jeton_d_ecran_n_envoie_qu_un_lien(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $compte = $this->expertComptable($magasin);
        $proprietaire = $this->proprietaireDe($magasin);
        $avant = Mail::sent(LienDuMotDePasseDeLExpertComptableMail::class)->count();

        $jeton = (string) Str::uuid();
        $this->assertTrue(app(RedonnerLAccesAction::class)->parUnNouveauLien($proprietaire, $compte, $jeton));
        $this->assertFalse(app(RedonnerLAccesAction::class)->parUnNouveauLien($proprietaire, $compte->fresh(), $jeton));

        $this->assertSame($avant + 1, Mail::sent(LienDuMotDePasseDeLExpertComptableMail::class)->count());
    }

    public function test_au_plus_cinq_liens_par_jour_et_par_compte(): void
    {
        $this->travelTo(self::aParis('2026-11-05 09:00:00'));
        $magasin = $this->ouvrirUneSociete();
        $compte = $this->expertComptable($magasin);
        $proprietaire = $this->proprietaireDe($magasin);

        foreach (range(1, RedonnerLAccesAction::LIENS_PAR_JOUR) as $envoi) {
            $this->assertTrue(app(RedonnerLAccesAction::class)->parUnNouveauLien($proprietaire, $compte->fresh(), (string) Str::uuid()));
        }

        $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->parUnNouveauLien($proprietaire, $compte->fresh(), (string) Str::uuid()), LogicException::class, RedonnerLAccesAction::MESSAGE_TROP_DE_LIENS);

        $this->travelTo(self::aParis('2026-11-06 09:00:01'));
        $this->assertTrue(app(RedonnerLAccesAction::class)->parUnNouveauLien($proprietaire, $compte->fresh(), (string) Str::uuid()));
    }

    public function test_le_proprietaire_corrige_l_adresse_tant_que_la_personne_n_a_pas_choisi_son_mot_de_passe(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $compte = $this->declarerSonExpertComptable($magasin, nom: 'Sophie Lenoir', email: 'sophie.lenoit@petit-associes.test');
        $premier = $this->jetonDuDernierLienDExpertComptable();
        $proprietaire = $this->proprietaireDe($magasin);
        $action = app(CorrigerLAdresseDeLExpertComptableAction::class);

        $this->assertThrows(fn () => $action->handle($proprietaire, $compte, 'sophie.lenoir@petit-associes.test', 'sophie.lenoir@petit-associes.test', 'mauvais'), LogicException::class, MotDePasseRedemande::MESSAGE);
        $this->assertThrows(fn () => $action->handle($proprietaire, $compte, 'sophie.lenoir@petit-associes.test', 'autre@petit-associes.test', 'password'), ValidationException::class);
        $this->assertTrue($action->handle($proprietaire, $compte, 'Sophie.Lenoir@petit-associes.test', 'Sophie.Lenoir@petit-associes.test', 'password'));
        $this->assertFalse($action->handle($proprietaire, $compte->fresh(), 'sophie.lenoir@petit-associes.test', 'sophie.lenoir@petit-associes.test', 'password'));

        $this->assertSame('sophie.lenoir@petit-associes.test', $compte->fresh()->email);
        Mail::assertSent(LienDuMotDePasseDeLExpertComptableMail::class, fn (LienDuMotDePasseDeLExpertComptableMail $mail): bool => $mail->hasTo('sophie.lenoir@petit-associes.test'));
        $this->get(route('experts-comptables.lien.afficher', ['jeton' => $premier]))->assertStatus(410);
        $this->get(route('experts-comptables.lien.afficher', ['jeton' => $this->jetonDuDernierLienDExpertComptable()]))->assertOk();
        $entree = app(TenantContext::class)->runAsSociete($magasin->societe, fn () => EntreeAudit::query()->where('action', ActionAuditee::AdresseDExpertComptableChangee->value)->sole());
        $this->assertStringNotContainsString('sophie.lenoir', json_encode($entree->nouvelles_valeurs, JSON_THROW_ON_ERROR));
    }

    public function test_l_adresse_ne_se_corrige_plus_une_fois_le_mot_de_passe_choisi_meme_sans_connexion(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $compte = $this->expertComptable($magasin);
        auth()->logout();

        $this->assertThrows(
            fn () => app(CorrigerLAdresseDeLExpertComptableAction::class)->handle($this->proprietaireDe($magasin), $compte, 'autre@petit-associes.test', 'autre@petit-associes.test', 'password'),
            LogicException::class,
            CorrigerLAdresseDeLExpertComptableAction::MESSAGE_MOT_DE_PASSE_DEJA_CHOISI,
        );
        $this->assertSame('marc.petit@petit-associes.test', $compte->fresh()->email);
    }

    public function test_le_profil_garde_l_adresse_obligatoire_d_un_expert_comptable(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $compte = $this->expertComptable($magasin);
        $this->actingAs($compte);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($magasin);

        Livewire::test(ModifierProfil::class)
            ->assertSee(ModifierProfil::AIDE_ADRESSE_EXPERT_COMPTABLE)
            ->fillForm(['email' => '', 'mot_de_passe_actuel' => self::MOT_DE_PASSE_CHOISI])
            ->call('save')
            ->assertHasFormErrors(['email' => 'required']);

        $this->assertSame('marc.petit@petit-associes.test', $compte->fresh()->email);
    }

    /**
     * La page du lien de la nouvelle adresse d'un expert-comptable de la
     * société, et sa réponse, ne parlent jamais d'une adresse de connexion :
     * cette adresse ne sert jamais à se connecter (P13).
     */
    public function test_la_page_du_lien_de_la_nouvelle_adresse_d_un_expert_comptable_ne_parle_pas_de_connexion(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $compte = $this->expertComptable($magasin);
        $this->actingAs($compte);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($magasin);
        Livewire::test(ModifierProfil::class)
            ->fillForm(['email' => 'm.petit@petit-associes.test', 'mot_de_passe_actuel' => self::MOT_DE_PASSE_CHOISI])
            ->call('save')
            ->assertHasNoFormErrors();
        $jeton = Str::afterLast((string) Mail::sent(ConfirmationNouvelleAdresseMail::class)->last()?->lien, '/');
        app(TenantContext::class)->clear();

        $this->get(route('profil.adresse.afficher', ['jeton' => $jeton]))
            ->assertOk()
            ->assertSeeText('Confirmez m.petit@petit-associes.test comme nouvelle adresse professionnelle de votre compte')
            ->assertSeeText('Confirmer ma nouvelle adresse')
            ->assertDontSeeText('adresse de connexion');
        $this->post(route('profil.adresse.confirmer', ['jeton' => $jeton]))
            ->assertOk()
            ->assertSeeText('Votre adresse professionnelle est désormais m.petit@petit-associes.test')
            ->assertDontSeeText('adresse de connexion');
        $this->assertSame('m.petit@petit-associes.test', $compte->fresh()->email);
    }

    public function test_la_nouvelle_adresse_d_un_expert_comptable_attend_son_lien_et_l_ancienne_est_prevenue(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $compte = $this->expertComptable($magasin);
        $this->actingAs($compte);
        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($magasin);

        Livewire::test(ModifierProfil::class)
            ->fillForm(['email' => 'm.petit@petit-associes.test', 'mot_de_passe_actuel' => self::MOT_DE_PASSE_CHOISI])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('marc.petit@petit-associes.test', $compte->fresh()->email);
        $this->assertSame('m.petit@petit-associes.test', $compte->fresh()->nouvelle_adresse);
        $confirmation = Mail::sent(ConfirmationNouvelleAdresseMail::class)->last();
        $this->assertNotNull($confirmation);
        $this->assertTrue($confirmation->hasTo('m.petit@petit-associes.test'));
        $this->assertStringNotContainsString('adresse de connexion', $confirmation->render());

        auth()->logout();
        app(TenantContext::class)->clear();
        app(ConfirmerNouvelleAdresseAction::class)->handle(Str::afterLast($confirmation->lien, '/'));

        $this->assertSame('m.petit@petit-associes.test', $compte->fresh()->email);
        $this->assertNull($compte->fresh()->email_verified_at);
        Mail::assertSent(AdresseChangeeMail::class, fn (AdresseChangeeMail $mail): bool => $mail->hasTo('marc.petit@petit-associes.test') && ! $mail->adresseDeConnexion);
        $this->assertSame(1, app(TenantContext::class)->runAsSociete($magasin->societe, fn () => EntreeAudit::query()->where('action', ActionAuditee::AdresseDExpertComptableChangee->value)->count()));
    }

    public function test_depuis_sa_fiche_le_proprietaire_envoie_un_nouveau_lien_corrige_l_adresse_et_reintegre_l_expert_comptable(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $sophie = $this->declarerSonExpertComptable($magasin, nom: 'Sophie Lenoir', email: 'sophie.lenoit@petit-associes.test');
        $marc = $this->ajouterUnExpertComptable($magasin->societe, 'Marc Petit', 'marc.petit@petit-associes.test');
        $proprietaire = $this->proprietaireDe($magasin);
        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);

        Livewire::test(FicheDuMembre::class, ['record' => $sophie->getRouteKey()])
            ->assertActionHidden('changerLeRoleDeSociete')
            ->assertActionHidden('transmettreLaPropriete')
            ->callAction('corrigerLAdresse', ['email' => 'sophie.lenoir@petit-associes.test', 'email_confirmation' => 'sophie.lenoir@petit-associes.test', 'mot_de_passe' => 'password'])
            ->assertActionMounted(['corrigerLAdresse', 'confirmerLaCorrection'])
            ->assertSeeHtml('<strong>sophie.lenoir@petit-associes.test</strong>')
            ->callMountedAction()
            ->assertNotified('Adresse corrigée : un nouveau lien est parti à la nouvelle adresse.');
        $this->assertSame('sophie.lenoir@petit-associes.test', $sophie->fresh()->email);

        Livewire::test(FicheDuMembre::class, ['record' => $sophie->getRouteKey()])
            ->callAction('redonnerLAcces', ['mode' => 'lien'])
            ->assertNotified('Accès redonné : un nouveau lien, valable 7 jours, est parti à sophie.lenoir@petit-associes.test.');
        $this->assertSame(ModeDAccesRedonne::Lien, $sophie->fresh()->acces_redonne_mode);

        Livewire::test(FicheDuMembre::class, ['record' => $marc->getRouteKey()])->callAction('retirerDeLaSociete');
        $this->assertSame(StatutCompte::Retire, $marc->fresh()->statut);
        Livewire::test(FicheDuMembre::class, ['record' => $marc->getRouteKey()])->callAction('reintegrer');
        $this->assertSame(StatutCompte::Actif, $marc->fresh()->statut);
    }

    public function test_les_fenetres_suspendre_et_retirer_d_un_expert_comptable_disent_son_lien_et_la_derniere_personne(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $marc = $this->declarerSonExpertComptable($magasin);
        $sophie = $this->ajouterUnExpertComptable($magasin->societe, 'Sophie Lenoir', 'sophie.lenoir@petit-associes.test');
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        Livewire::test(FicheDuMembre::class, ['record' => $marc->getRouteKey()])
            ->mountAction('suspendre')
            ->assertSee('Son lien en cours ne vaudra plus.');
        Livewire::test(FicheDuMembre::class, ['record' => $marc->getRouteKey()])
            ->mountAction('retirerDeLaSociete')
            ->assertSee('la société pourra le réintégrer')
            ->assertDontSee('C\'est la dernière personne de votre expert-comptable déclaré');

        app(ChoisirLeMotDePasseDeLExpertComptableAction::class)->handle($this->jetonDuDernierLienDExpertComptable(), self::MOT_DE_PASSE_CHOISI, self::MOT_DE_PASSE_CHOISI);
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        Livewire::test(FicheDuMembre::class, ['record' => $sophie->getRouteKey()])
            ->mountAction('suspendre')
            ->assertDontSee('Son lien en cours ne vaudra plus.');

        Livewire::test(FicheDuMembre::class, ['record' => $marc->getRouteKey()])->callAction('retirerDeLaSociete');
        Livewire::test(FicheDuMembre::class, ['record' => $sophie->getRouteKey()])
            ->mountAction('retirerDeLaSociete')
            ->assertSee('C\'est la dernière personne de votre expert-comptable déclaré : la retirer met fin à la déclaration.');
    }

    public function test_la_liste_de_l_equipe_montre_l_expert_comptable_et_l_etat_de_son_lien_au_proprietaire_seul(): void
    {
        $this->travelTo(self::aParis('2026-11-05 10:00:00'));
        $magasin = $this->ouvrirUneSociete();
        app(RepertoireSirene::class)->nommer(FauxRepertoireSirene::SIREN_CABINET, 'CABINET PETIT ET ASSOCIES');
        $marc = $this->declarerSonExpertComptable($magasin);
        $administrateur = $this->administrateur($magasin, autorisationsSociete: AutorisationsSociete::toutes());
        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));

        Livewire::test(ListMembres::class)
            ->assertCanSeeTableRecords([$marc])
            ->assertSee('Expert-comptable déclaré · cabinet CABINET PETIT ET ASSOCIES')
            ->assertSee('Lien valable jusqu\'au 12/11/2026')
            ->assertSee('Mot de passe pas encore choisi')
            ->assertSee('Tous les magasins réels (expert-comptable)');
        Livewire::test(FicheDuMembre::class, ['record' => $marc->getRouteKey()])
            ->assertSee('Adresse e-mail professionnelle (reçoit les liens de son mot de passe)')
            ->assertSee('Pas encore choisi')
            ->assertSee('Autorisations de l\'expert-comptable')
            ->assertSee('Clients : voir');

        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        Livewire::test(ListMembres::class)->assertCanNotSeeTableRecords([$marc]);
    }

    private function seConnecter(string $saisie, string $motDePasse): Testable
    {
        $this->flushSession();
        auth()->logout();
        Filament::setCurrentPanel(Filament::getPanel('app'));

        return Livewire::withCookies([PostesReconnus::TEMOIN => '', PostesConnus::TEMOIN => ''])
            ->test(Connexion::class)
            ->fillForm(['login' => $saisie, 'password' => $motDePasse, 'remember' => false])
            ->call('authenticate');
    }

    /**
     * Un vendeur connecté dans ce navigateur, qui a ouvert son magasin,
     * suit le lien de l'expert-comptable : sa session se ferme d'abord, et
     * sa fin s'inscrit au journal des événements de son magasin (JET 40),
     * avant que l'expert-comptable soit connecté.
     */
    public function test_suivre_le_lien_depuis_la_session_d_un_autre_compte_ferme_d_abord_cette_session(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $compte = $this->declarerSonExpertComptable($magasin);
        $jeton = $this->jetonDuDernierLienDExpertComptable();
        $this->actingAs($vendeur)->get("/app/{$magasin->uuid}")->assertOk();

        $this->post(route('experts-comptables.lien.choisir', ['jeton' => $jeton]), ['password' => self::MOT_DE_PASSE_CHOISI, 'password_confirmation' => self::MOT_DE_PASSE_CHOISI])->assertRedirect();

        $this->assertAuthenticatedAs($compte);
        $fins = app(TenantContext::class)->runAs($magasin, fn () => EnregistrementFiscal::query()->where('type', (string) CodeEvenement::FinDeSession->value)->get());
        $this->assertCount(1, $fins);
        $this->assertSame($vendeur->id, $fins->sole()->contenuSigne()['operateur']['id']);
    }

    public function test_le_lien_d_un_compte_suspendu_ou_retire_d_une_declaration_terminee_ou_d_une_societe_revoquee_ne_sert_plus(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $proprietaire = $this->proprietaireDe($magasin);
        $marc = $this->declarerSonExpertComptable($magasin);
        $deMarc = $this->jetonDuDernierLienDExpertComptable();
        $ines = $this->ajouterUnExpertComptable($magasin->societe, 'Inès Garnier', 'ines.garnier@petit-associes.test');
        $dInes = $this->jetonDuDernierLienDExpertComptable();
        $paul = $this->ajouterUnExpertComptable($magasin->societe, 'Paul Roux', 'paul.roux@petit-associes.test');
        $dePaul = $this->jetonDuDernierLienDExpertComptable();

        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $marc);
        app(ChangerStatutCompteAction::class)->reactiver($proprietaire, $marc->fresh());
        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $ines);
        app(TenantContext::class)->clear();

        foreach ([$deMarc, $dInes] as $jeton) {
            $this->get(route('experts-comptables.lien.afficher', ['jeton' => $jeton]))->assertStatus(410)->assertSee(LienDeLExpertComptableInvalideException::MESSAGE);
            $this->post(route('experts-comptables.lien.choisir', ['jeton' => $jeton]), ['password' => self::MOT_DE_PASSE_CHOISI, 'password_confirmation' => self::MOT_DE_PASSE_CHOISI])->assertStatus(410);
        }
        $this->get(route('experts-comptables.lien.afficher', ['jeton' => $dePaul]))->assertOk();

        $declaration = LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $magasin->societe_id)->where('etat', EtatDuLien::Declare->value)->sole();
        app(MettreFinAuLienAction::class)->handle($proprietaire, $declaration);
        app(TenantContext::class)->clear();
        $this->get(route('experts-comptables.lien.afficher', ['jeton' => $dePaul]))->assertStatus(410);

        $autre = $this->ouvrirUneSociete(['nom' => 'Ailleurs', 'siren' => SocieteFactory::sirenFictif()]);
        $this->declarerSonExpertComptable($autre, nom: 'Léa Blanc', email: 'lea.blanc@petit-associes.test');
        $deLea = $this->jetonDuDernierLienDExpertComptable();
        $this->get(route('experts-comptables.lien.afficher', ['jeton' => $deLea]))->assertOk();
        $this->revoquerLaSociete($autre->societe);

        $this->get(route('experts-comptables.lien.afficher', ['jeton' => $deLea]))->assertStatus(410);
        $this->post(route('experts-comptables.lien.choisir', ['jeton' => $deLea]), ['password' => self::MOT_DE_PASSE_CHOISI, 'password_confirmation' => self::MOT_DE_PASSE_CHOISI])->assertStatus(410);
        $this->assertGuest();
    }

    public function test_la_page_du_lien_ferme_les_autres_sessions_du_compte(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $compte = $this->declarerSonExpertComptable($magasin);
        DB::table('sessions')->insert(['id' => 'session-ouverte-ailleurs', 'user_id' => $compte->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);
        DB::table('sessions')->insert(['id' => 'session-d-un-autre-compte', 'user_id' => $this->proprietaireDe($magasin)->id, 'payload' => '', 'last_activity' => now()->getTimestamp()]);

        $this->post(route('experts-comptables.lien.choisir', ['jeton' => $this->jetonDuDernierLienDExpertComptable()]), ['password' => self::MOT_DE_PASSE_CHOISI, 'password_confirmation' => self::MOT_DE_PASSE_CHOISI])->assertRedirect();

        $this->assertSame([], DB::table('sessions')->where('id', 'session-ouverte-ailleurs')->pluck('id')->all());
        $this->assertSame(['session-d-un-autre-compte'], DB::table('sessions')->where('id', 'session-d-un-autre-compte')->pluck('id')->all());
        $this->assertAuthenticatedAs($compte);
    }

    /**
     * L'adresse d'un expert-comptable de la société n'est qu'un contact :
     * même quand elle est l'adresse de connexion du propriétaire d'une
     * autre société, « Mot de passe oublié » ne sert que ce propriétaire,
     * et l'adresse propre de l'expert-comptable ne reçoit rien, avec la
     * même réponse.
     */
    public function test_mot_de_passe_oublie_n_ouvre_jamais_un_compte_d_expert_comptable_meme_quand_son_adresse_est_l_adresse_de_connexion_d_un_proprietaire(): void
    {
        $this->travelTo(self::aParis('2026-10-09 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $autre = $this->ouvrirUneSociete(['nom' => 'Ailleurs', 'siren' => SocieteFactory::sirenFictif()]);
        $proprietaireDAilleurs = $this->proprietaireDe($autre);
        $adresseDeConnexion = (string) $proprietaireDAilleurs->email;
        $this->assertNotSame('', $adresseDeConnexion);
        $marc = $this->expertComptable($magasin);
        $ines = $this->ajouterUnExpertComptable($magasin->societe, 'Inès Garnier', $adresseDeConnexion);
        Notification::fake();
        Filament::setCurrentPanel(Filament::getPanel('app'));

        foreach (['marc.petit@petit-associes.test', $adresseDeConnexion] as $adresse) {
            Livewire::test(DemandeDeLien::class)->fillForm(['email' => $adresse])->call('request')->assertHasNoFormErrors()->assertNotified(DemandeDeLien::REPONSE);
            $this->travel(2)->minutes();
        }

        Notification::assertNothingSentTo($marc);
        Notification::assertNothingSentTo($ines);
        Notification::assertSentTo($proprietaireDAilleurs, LienPourChoisirSonMotDePasse::class);
        Notification::assertSentTimes(LienPourChoisirSonMotDePasse::class, 1);
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'marc.petit@petit-associes.test')->count());
    }

    public function test_l_acces_redonne_par_un_lien_s_affiche_a_la_personne_et_sur_sa_fiche_avec_son_mode(): void
    {
        $this->travelTo(self::aParis('2026-12-15 16:05:00'));
        $magasin = $this->ouvrirUneSociete();
        $compte = $this->expertComptable($magasin);
        $proprietaire = $this->proprietaireDe($magasin);
        auth()->logout();
        $this->travelTo(self::aParis('2026-12-16 09:30:00'));

        $this->assertTrue(app(RedonnerLAccesAction::class)->parUnNouveauLien($proprietaire, $compte, (string) Str::uuid()));
        $this->assertSame("Le 16/12/2026 à 09:30 par {$proprietaire->name}, par un lien envoyé à son adresse", MembreResource::accesRedonne($compte->fresh()));

        $this->post(route('experts-comptables.lien.choisir', ['jeton' => $this->jetonDuDernierLienDExpertComptable()]), ['password' => 'Autre-Mot-2026!', 'password_confirmation' => 'Autre-Mot-2026!'])->assertRedirect();

        $this->assertAuthenticatedAs($compte);
        $avis = json_encode(session('filament.notifications'), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString("Votre accès a été redonné le 16/12/2026 à 09:30 par {$proprietaire->name}, par un lien envoyé à votre adresse.", $avis);
        $this->assertSame("Le 16/12/2026 à 09:30 par {$proprietaire->name}, par un lien envoyé à son adresse", MembreResource::accesRedonne($compte->fresh()));
    }

    /**
     * Tout est relu au moment de l'envoi : un lien dont l'échéance est
     * passée, ou que demandait un compte qui n'est plus le propriétaire,
     * ne part pas, et le propriétaire actuel en est prévenu.
     */
    public function test_l_e_mail_qui_ne_peut_plus_partir_au_moment_de_l_envoi_previent_le_proprietaire(): void
    {
        $this->travelTo(self::aParis('2026-11-03 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateur($magasin);
        $compte = $this->declarerSonExpertComptable($magasin);
        $jeton = $this->jetonDuDernierLienDExpertComptable();
        $envoyes = count(Mail::sent(LienDuMotDePasseDeLExpertComptableMail::class));

        app(EnvoiDesLiensDesExpertsComptables::class)->envoyer($compte->id, $jeton, $administrateur->id, false);
        $this->travelTo(self::aParis('2026-11-10 10:00:01'));
        app(EnvoiDesLiensDesExpertsComptables::class)->envoyer($compte->id, $jeton, $proprietaire->id, false);

        $this->assertSame($envoyes, count(Mail::sent(LienDuMotDePasseDeLExpertComptableMail::class)));
        $avis = $proprietaire->notifications()->where('type', AvisDeLEquipe::class)->where('data->title', 'E-mail non envoyé')->get();
        $this->assertCount(2, $avis);
        $this->assertStringContainsString("Le lien de Marc Petit n'est pas parti", (string) $avis->first()->data['body']);
    }

    public function test_chaque_lien_compte_dans_les_e_mails_du_proprietaire(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $proprietaire = $this->proprietaireDe($magasin);
        $cle = 'emails:personne:'.CleDuModele::de($proprietaire);
        $marc = $this->declarerSonExpertComptable($magasin);
        $ines = $this->ajouterUnExpertComptable($magasin->societe, 'Inès Garnier', 'ines.garnier@petit-associes.test');
        app(RedonnerLAccesAction::class)->parUnNouveauLien($proprietaire, $marc, (string) Str::uuid());
        app(TenantContext::class)->clear();
        app(CorrigerLAdresseDeLExpertComptableAction::class)->handle($proprietaire, $ines->fresh(), 'ines@petit-associes.test', 'ines@petit-associes.test', 'password');
        app(TenantContext::class)->clear();

        $this->assertSame(4, RateLimiter::attempts($cle));

        foreach (range(5, DebitDesEmails::PAR_HEURE_ET_PAR_PERSONNE) as $numero) {
            RateLimiter::hit($cle, 3600);
        }
        $envoyes = count(Mail::sent(LienDuMotDePasseDeLExpertComptableMail::class));
        $this->assertThrows(fn () => $this->ajouterUnExpertComptable($magasin->societe, 'Paul Roux', 'paul.roux@petit-associes.test'), LogicException::class, 'Trop d\'e-mails envoyés en une heure');
        $this->assertSame($envoyes, count(Mail::sent(LienDuMotDePasseDeLExpertComptableMail::class)));
        $this->assertSame(0, User::query()->where('name', 'Paul Roux')->count());
    }

    public function test_la_premiere_connexion_de_l_expert_comptable_previent_le_proprietaire(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon 1']);
        $proprietaire = $this->proprietaireDe($magasin);
        $compte = $this->declarerSonExpertComptable($magasin);

        $this->post(route('experts-comptables.lien.choisir', ['jeton' => $this->jetonDuDernierLienDExpertComptable()]), ['password' => self::MOT_DE_PASSE_CHOISI, 'password_confirmation' => self::MOT_DE_PASSE_CHOISI])->assertRedirect();
        auth()->logout();
        app(SuiviDesConnexions::class)->noterLaConnexion($compte->fresh());

        $avis = $proprietaire->notifications()->where('data->title', 'Première connexion')->get();
        $this->assertCount(1, $avis);
        $this->assertSame("{$compte->name} s'est connecté à son compte pour la première fois.", $avis->sole()->data['body']);
    }
}
