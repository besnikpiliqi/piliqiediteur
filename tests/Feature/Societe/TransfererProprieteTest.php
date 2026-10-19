<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\ContrePasserPaiementAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerPaiementAction;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Actions\ModifierFactureAction;
use App\Domain\Facturation\Enums\ModeFermetureCaisse;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Services\VerificateurCaissesPersonnelles;
use App\Domain\Facturation\Support\GardesDeLaVente;
use App\Domain\Tenancy\Actions\AccepterLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\AnnulerLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\DemanderLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\ModifierAdministrateurAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Mail\TransmissionDeLaProprieteMail;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\TransmissionPropriete;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\MotDePasseRedemande;
use App\Filament\App\Pages\Auth\Connexion;
use App\Filament\App\Pages\Caisse;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource\Pages\ListClotureCaissePersonnelles;
use App\Filament\App\Resources\ClotureCaisseResource\Pages\ListClotureCaisses;
use App\Filament\Societe\Pages\AccepterLaTransmission;
use App\Filament\Societe\Resources\MembreResource\Pages\FicheDuMembre;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Le propriétaire, et lui seul, demande la transmission de la propriété de
 * sa société, en redonnant son mot de passe, à un administrateur actif dont
 * la qualité de représentant est prouvée à son nom, sans accès redonné
 * depuis, qui a choisi son propre mot de passe et renseigné lui-même son
 * adresse : un lien, valable 24 heures, part à cette adresse, et la
 * propriété ne passe que quand l'administrateur le suit, connecté à son
 * compte. L'ancien propriétaire en devient administrateur de tous les
 * magasins, avec toutes les autorisations sauf celles de la vente, que le
 * nouveau propriétaire peut lui cocher ; la société garde toujours un seul
 * propriétaire, qui ne vend jamais.
 */
class TransfererProprieteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Le mot de passe que la fabrique donne au propriétaire.
     */
    private const MOT_DE_PASSE_DU_PROPRIETAIRE = 'password';

    public function test_le_proprietaire_transmet_la_propriete_a_un_administrateur_dont_la_qualite_est_prouvee(): void
    {
        Notification::fake();
        Mail::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurPretAReprendre($magasin);

        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        Livewire::test(FicheDuMembre::class, ['record' => $administrateur->getRouteKey()])
            ->assertActionVisible('transmettreLaPropriete')
            ->callAction('transmettreLaPropriete', ['mot_de_passe' => self::MOT_DE_PASSE_DU_PROPRIETAIRE])
            ->assertHasNoActionErrors()
            ->assertNotified('Un lien a été envoyé à l\'adresse de cet administrateur : la propriété ne passera que quand il l\'aura suivi.');
        $this->accepter($administrateur, $this->jetonDuDernierLienDeTransmission());

        $this->assertSame(RoleSociete::Proprietaire, $administrateur->refresh()->role_societe);
        $this->assertSame(RoleSociete::Administrateur, $proprietaire->refresh()->role_societe);
        $this->assertTrue($this->proprietaireDe($magasin)->is($administrateur));
        foreach ([$magasin, $autre] as $chacun) {
            $siens = collect($this->modificationsDesDroits($chacun))->where('membre.id', $administrateur->id)->last();
            $this->assertSame(RoleSociete::Proprietaire->value, $siens['apres']['role_societe'], $chacun->nom);
            $anciens = collect($this->modificationsDesDroits($chacun))->where('membre.id', $proprietaire->id)->last();
            $this->assertSame(RoleSociete::Administrateur->value, $anciens['apres']['role_societe'], $chacun->nom);
        }
        [$entree] = $this->entreesDAudit($magasin->societe, ActionAuditee::ProprieteTransferee);
        $this->assertSame(RoleSociete::Proprietaire->value, $entree->nouvelles_valeurs['droits'][$administrateur->id]['role_societe']);
        $this->assertSame(RoleSociete::Administrateur->value, $entree->nouvelles_valeurs['droits'][$proprietaire->id]['role_societe']);
        Notification::assertSentTo($administrateur, AvisDeLEquipe::class, fn (AvisDeLEquipe $avis): bool => $avis->titre === 'Vous êtes propriétaire'
            && str_contains($avis->texte, 'vous avez désormais tous les droits dans la société et dans chacun de ses magasins, sauf celui de vendre : le propriétaire supervise et contrôle.'));
        Notification::assertSentTo($proprietaire, AvisDeLEquipe::class, fn (AvisDeLEquipe $avis): bool => $avis->titre === 'Propriété transmise'
            && str_contains($avis->texte, 'vous en êtes désormais administrateur de tous les magasins, avec toutes les autorisations de magasin sauf celles de la vente, que le nouveau propriétaire peut vous cocher, et toutes celles de la société.'));
    }

    /**
     * Demander la transmission ne change rien : la page du lien mène qui
     * n'est pas connecté à la connexion, et ne propose la propriété qu'à
     * l'administrateur connecté à son propre compte, qui la confirme en
     * redonnant son mot de passe ; c'est seulement là qu'elle passe.
     */
    public function test_la_transmission_ne_prend_effet_qu_au_lien_suivi_par_l_administrateur_connecte_a_son_compte(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurPretAReprendre($magasin);

        $jeton = $this->demanderLaTransmission($proprietaire, $administrateur);

        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
        $this->assertSame([RoleSociete::Administrateur, []], [$administrateur->refresh()->role_societe, $this->entreesDAudit($magasin->societe, ActionAuditee::ProprieteTransferee)]);
        Mail::assertSent(TransmissionDeLaProprieteMail::class, fn (TransmissionDeLaProprieteMail $courriel): bool => $courriel->hasTo('repreneur@example.test') && $courriel->lien !== null);
        $this->get(route('filament.societe.transmission', ['jeton' => $jeton]))->assertRedirect();
        $this->assertGuest();

        $this->actingAs($administrateur)->get(route('filament.societe.transmission', ['jeton' => $jeton]))
            ->assertOk()
            ->assertSee('En confirmant, vous devenez le propriétaire de la société');
        Filament::setCurrentPanel(Filament::getPanel('societe'));
        Livewire::test(AccepterLaTransmission::class, ['jeton' => $jeton])
            ->fillForm(['mot_de_passe' => 'pas-le-bon'])
            ->call('accepter')
            ->assertNotified(MotDePasseRedemande::MESSAGE);
        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));

        Livewire::test(AccepterLaTransmission::class, ['jeton' => $jeton])
            ->fillForm(['mot_de_passe' => self::MOT_DE_PASSE_CHOISI])
            ->call('accepter')
            ->assertHasNoFormErrors()
            ->assertRedirect();

        $this->assertTrue($this->proprietaireDe($magasin)->is($administrateur));
        $this->assertSame(['repreneur@example.test', true], [$administrateur->refresh()->email, $administrateur->email_verified_at !== null]);
    }

    /**
     * Un lien expiré, annulé, déjà suivi, ou ouvert par un autre compte que
     * son destinataire, ne propose rien et ne change rien.
     */
    public function test_un_lien_de_transmission_expire_annule_deja_suivi_ou_ouvert_par_un_autre_compte_ne_change_rien(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurPretAReprendre($magasin);
        $autreAdministrateur = $this->administrateurPretAReprendre($magasin, 'autre@example.test');

        $jeton = $this->demanderLaTransmission($proprietaire, $administrateur);
        $this->actingAs($autreAdministrateur)->get(route('filament.societe.transmission', ['jeton' => $jeton]))->assertOk()->assertSee(AccepterLaTransmissionDeLaProprieteAction::MESSAGE_LIEN);
        $this->assertThrows(fn () => app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($autreAdministrateur, $jeton, self::MOT_DE_PASSE_CHOISI), LogicException::class, AccepterLaTransmissionDeLaProprieteAction::MESSAGE_LIEN);

        $this->travel(TransmissionPropriete::DUREE_DE_VALIDITE_EN_HEURES + 1)->hours();
        $this->assertThrows(fn () => app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($administrateur, $jeton, self::MOT_DE_PASSE_CHOISI), LogicException::class, AccepterLaTransmissionDeLaProprieteAction::MESSAGE_LIEN);

        $jeton = $this->demanderLaTransmission($proprietaire, $administrateur);
        app(AnnulerLaTransmissionDeLaProprieteAction::class)->handle($proprietaire, $administrateur);
        $this->assertThrows(fn () => app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($administrateur, $jeton, self::MOT_DE_PASSE_CHOISI), LogicException::class, AccepterLaTransmissionDeLaProprieteAction::MESSAGE_LIEN);
        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::TransmissionAnnulee));

        $jeton = $this->demanderLaTransmission($proprietaire, $administrateur);
        app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($administrateur, $jeton, self::MOT_DE_PASSE_CHOISI);
        $this->assertThrows(fn () => app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($administrateur->refresh(), $jeton, self::MOT_DE_PASSE_CHOISI), LogicException::class, AccepterLaTransmissionDeLaProprieteAction::MESSAGE_LIEN);
        $this->assertTrue($this->proprietaireDe($magasin)->is($administrateur));
        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::ProprieteTransferee));
    }

    /**
     * Une demande faite, puis la société révoquée par l'éditeur : plus rien
     * ne s'y gère, et l'administrateur qui suit le lien ne devient pas
     * propriétaire ; rien ne change.
     */
    public function test_une_transmission_demandee_ne_s_accepte_plus_dans_une_societe_revoquee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurPretAReprendre($magasin);
        $jeton = $this->demanderLaTransmission($proprietaire, $administrateur);

        $magasin->societe->forceFill(['statut' => StatutSociete::Revoquee])->save();

        $this->assertThrows(fn () => app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($administrateur, $jeton, self::MOT_DE_PASSE_CHOISI), LogicException::class, AccepterLaTransmissionDeLaProprieteAction::MESSAGE_LIEN);
        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
        $this->assertSame([RoleSociete::Administrateur, RoleSociete::Proprietaire], [$administrateur->refresh()->role_societe, $proprietaire->refresh()->role_societe]);
        $this->assertNull(TransmissionPropriete::query()->where('destinataire_id', $administrateur->id)->sole()->acceptee_le);
        $this->assertSame([], $this->entreesDAudit($magasin->societe, ActionAuditee::ProprieteTransferee));
    }

    /**
     * La transmission exige un administrateur actif, dont la qualité est
     * prouvée, qui a renseigné lui-même son adresse : à la demande, chacun
     * avec son message, et de nouveau à l'acceptation.
     */
    public function test_la_transmission_exige_un_administrateur_actif_a_la_qualite_prouvee_qui_a_renseigne_lui_meme_son_adresse(): void
    {
        Mail::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $suspendu = $this->administrateurPretAReprendre($magasin, 'suspendu@example.test');
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $suspendu);
        $sansQualite = $this->renseignerSonAdresse($this->administrateur($magasin, null, AutorisationsSociete::toutes()), 'sans-qualite@example.test');
        $sansAdresse = tap($this->administrateur($magasin, null, AutorisationsSociete::toutes())->forceFill(['qualite_verifiee_le' => now()]))->save();

        foreach ([
            [$suspendu->refresh(), AuthorizationException::class, DemanderLaTransmissionDeLaProprieteAction::MESSAGE_ADMINISTRATEUR],
            [$sansQualite, LogicException::class, DemanderLaTransmissionDeLaProprieteAction::MESSAGE_QUALITE],
            [$sansAdresse, LogicException::class, DemanderLaTransmissionDeLaProprieteAction::MESSAGE_ADRESSE],
        ] as [$cible, $refus, $message]) {
            $this->assertThrows(fn () => app(DemanderLaTransmissionDeLaProprieteAction::class)->handle($proprietaire, $cible, self::MOT_DE_PASSE_DU_PROPRIETAIRE), $refus, $message);
        }
        Mail::assertNothingSent();
        $this->assertSame(0, TransmissionPropriete::query()->count());

        $administrateur = $this->administrateurPretAReprendre($magasin);
        $jeton = $this->demanderLaTransmission($proprietaire, $administrateur);
        $administrateur->forceFill(['email' => null])->save();
        $this->assertThrows(fn () => app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($administrateur->refresh(), $jeton, self::MOT_DE_PASSE_CHOISI), LogicException::class, DemanderLaTransmissionDeLaProprieteAction::MESSAGE_ADRESSE);
        $this->renseignerSonAdresse($administrateur, 'repreneur@example.test');
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $administrateur);
        $this->assertThrows(fn () => app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($administrateur->refresh(), $jeton, self::MOT_DE_PASSE_CHOISI), AuthorizationException::class, DemanderLaTransmissionDeLaProprieteAction::MESSAGE_ADMINISTRATEUR);

        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
    }

    /**
     * Un administrateur dont le mot de passe est encore son identifiant,
     * que le propriétaire a connu à sa création, ne reçoit pas la propriété
     * : ni à la demande, ni à l'acceptation, la base refusant de toute façon
     * un propriétaire dans ce cas.
     */
    public function test_la_transmission_exige_un_administrateur_qui_a_choisi_son_mot_de_passe(): void
    {
        Mail::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $auMotDePasseIdentifiant = app(TenantContext::class)->runAs($magasin, fn (): User => app(CreerAdministrateurAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Alix'], null, PermissionRegistry::modeleDeLAdministrateur(), AutorisationsSociete::toutes()));
        $this->renseignerSonAdresse($auMotDePasseIdentifiant, 'alix@example.test')->forceFill(['qualite_verifiee_le' => now()])->save();

        $this->assertThrows(fn () => app(DemanderLaTransmissionDeLaProprieteAction::class)->handle($proprietaire, $auMotDePasseIdentifiant->refresh(), self::MOT_DE_PASSE_DU_PROPRIETAIRE), LogicException::class, DemanderLaTransmissionDeLaProprieteAction::MESSAGE_MOT_DE_PASSE);
        Mail::assertNothingSent();

        $administrateur = $this->administrateurPretAReprendre($magasin);
        $jeton = $this->demanderLaTransmission($proprietaire, $administrateur);
        $identifiant = (string) $administrateur->identifiantDeConnexion();
        $administrateur->forceFill(['password' => $identifiant, 'mot_de_passe_est_l_identifiant' => true])->save();

        $this->assertThrows(fn () => app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($administrateur->refresh(), $jeton, $identifiant), LogicException::class, DemanderLaTransmissionDeLaProprieteAction::MESSAGE_MOT_DE_PASSE);
        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
        $this->assertSame(RoleSociete::Administrateur, $administrateur->refresh()->role_societe);
    }

    /**
     * Un accès redonné après la preuve de qualité empêche la transmission
     * (qui l'a redonné pourrait accepter à la place de l'administrateur) :
     * la demande est refusée, et une demande déjà partie ne s'accepte plus.
     */
    public function test_un_acces_redonne_apres_la_preuve_de_qualite_empeche_la_transmission(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurPretAReprendre($magasin);
        $jeton = $this->demanderLaTransmission($proprietaire, $administrateur);
        $this->travel(1)->minute();

        app(RedonnerLAccesAction::class)->handle($proprietaire, $administrateur, true, 'un-nouveau-mot-de-passe');

        $this->assertThrows(fn () => app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($administrateur->refresh(), $jeton, 'un-nouveau-mot-de-passe'), LogicException::class, DemanderLaTransmissionDeLaProprieteAction::MESSAGE_QUALITE);
        $this->assertThrows(fn () => app(DemanderLaTransmissionDeLaProprieteAction::class)->handle($proprietaire, $administrateur->refresh(), self::MOT_DE_PASSE_DU_PROPRIETAIRE), LogicException::class, DemanderLaTransmissionDeLaProprieteAction::MESSAGE_QUALITE);
        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));

        $this->travel(1)->minute();
        $administrateur->forceFill(['qualite_verifiee_le' => now()])->save();
        $this->demanderLaTransmission($proprietaire, $administrateur->refresh());
        $this->assertSame(1, TransmissionPropriete::query()->whereNull('annulee_le')->whereNull('acceptee_le')->where('expire_le', '>', now())->count());
    }

    /**
     * L'adresse de l'administrateur ne peut pas être celle du propriétaire
     * actuel, dont « Mot de passe oublié » réinitialiserait ensuite le
     * compte du nouveau propriétaire : ni à la demande, ni à l'acceptation.
     */
    public function test_la_transmission_refuse_l_adresse_du_proprietaire_actuel(): void
    {
        Mail::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurPretAReprendre($magasin, $proprietaire->email);

        $this->assertThrows(fn () => app(DemanderLaTransmissionDeLaProprieteAction::class)->handle($proprietaire, $administrateur, self::MOT_DE_PASSE_DU_PROPRIETAIRE), LogicException::class, DemanderLaTransmissionDeLaProprieteAction::MESSAGE_ADRESSE_DU_PROPRIETAIRE);
        Mail::assertNothingSent();

        $this->renseignerSonAdresse($administrateur, 'repreneur@example.test');
        $jeton = $this->demanderLaTransmission($proprietaire, $administrateur->refresh());
        $this->renseignerSonAdresse($administrateur, $proprietaire->email);

        $this->assertThrows(fn () => app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($administrateur->refresh(), $jeton, self::MOT_DE_PASSE_CHOISI), LogicException::class, DemanderLaTransmissionDeLaProprieteAction::MESSAGE_ADRESSE_DU_PROPRIETAIRE);
        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
    }

    /**
     * Une adresse qui est déjà l'adresse de connexion du propriétaire d'une
     * autre société reçoit un courriel sans lien, et l'écran du propriétaire
     * dit comme toujours qu'un lien est parti ; une adresse prise par un
     * autre propriétaire entre la demande et l'acceptation fait refuser
     * l'acceptation : elle ne devient jamais l'adresse de connexion d'un
     * second propriétaire.
     */
    public function test_une_adresse_deja_utilisee_par_un_autre_proprietaire_ne_devient_pas_une_adresse_de_connexion_et_l_ecran_du_proprietaire_ne_le_revele_pas(): void
    {
        Mail::fake();
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Ailleurs']);
        $proprietaireAilleurs = $this->proprietaireDe($ailleurs);
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurPretAReprendre($magasin, $proprietaireAilleurs->email);

        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        Livewire::test(FicheDuMembre::class, ['record' => $administrateur->getRouteKey()])
            ->callAction('transmettreLaPropriete', ['mot_de_passe' => self::MOT_DE_PASSE_DU_PROPRIETAIRE])
            ->assertNotified('Un lien a été envoyé à l\'adresse de cet administrateur : la propriété ne passera que quand il l\'aura suivi.');
        Mail::assertSent(TransmissionDeLaProprieteMail::class, fn (TransmissionDeLaProprieteMail $courriel): bool => $courriel->hasTo($proprietaireAilleurs->email) && $courriel->lien === null);

        $this->renseignerSonAdresse($administrateur, 'repreneur@example.test');
        $jeton = $this->demanderLaTransmission($proprietaire, $administrateur->refresh());
        $proprietaireAilleurs->forceFill(['email' => 'repreneur@example.test'])->save();

        $this->assertThrows(fn () => app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($administrateur->refresh(), $jeton, self::MOT_DE_PASSE_CHOISI), LogicException::class, AccepterLaTransmissionDeLaProprieteAction::MESSAGE_ADRESSE_PRISE);
        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
        $this->assertSame(1, User::query()->where('role_societe', RoleSociete::Proprietaire->value)->where('email', 'repreneur@example.test')->count());
    }

    /**
     * L'ancien propriétaire garde son identifiant et se connecte avec lui ;
     * son adresse n'est plus qu'un contact, qui n'ouvre plus son compte ;
     * ses sessions sont fermées, son « Se souvenir de moi » ne vaut plus et
     * son lien « Mot de passe oublié » en attente est effacé.
     */
    public function test_l_ancien_proprietaire_garde_son_identifiant_perd_le_rappel_et_ne_se_connecte_plus_par_son_adresse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $proprietaire->forceFill(['remember_token' => 'jeton-de-rappel-de-l-ancien-proprietaire'])->save();
        $identifiant = (string) $proprietaire->identifiantDeConnexion();
        DB::table('password_reset_tokens')->insert(['email' => $proprietaire->email, 'token' => 'empreinte', 'created_at' => now()]);
        $this->ouvrirUneSessionAilleurs($proprietaire);
        $administrateur = $this->administrateurPretAReprendre($magasin);

        $this->transmettre($proprietaire, $administrateur);

        $ancien = $proprietaire->refresh();
        $this->assertSame($identifiant, $ancien->identifiantDeConnexion());
        $this->assertSessionsFermees($ancien);
        $this->assertNull(auth()->getProvider()->retrieveByToken($ancien->id, 'jeton-de-rappel-de-l-ancien-proprietaire'));
        $this->assertNull(auth()->getProvider()->retrieveByToken($ancien->id, (string) $ancien->remember_token));
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', $ancien->email)->count());
        $this->seConnecter($ancien->email, self::MOT_DE_PASSE_DU_PROPRIETAIRE)->assertHasErrors(['data.login']);
        $this->assertGuest();
        $this->seConnecter($identifiant, self::MOT_DE_PASSE_DU_PROPRIETAIRE)->assertHasNoErrors();
        $this->assertAuthenticatedAs($ancien);
    }

    public function test_le_nouveau_proprietaire_se_connecte_par_son_adresse_ou_son_identifiant(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateurPretAReprendre($magasin, ' Repreneur@Example.TEST ');
        $identifiant = (string) $administrateur->identifiantDeConnexion();
        $this->seConnecter('repreneur@example.test', self::MOT_DE_PASSE_CHOISI)->assertHasErrors(['data.login']);
        $this->assertGuest();

        $this->transmettre($this->proprietaireDe($magasin), $administrateur);

        $this->seConnecter('repreneur@example.test', self::MOT_DE_PASSE_CHOISI)->assertHasNoErrors();
        $this->assertAuthenticatedAs($administrateur->refresh());
        auth()->logout();
        $this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI)->assertHasNoErrors();
        $this->assertAuthenticatedAs($administrateur);
    }

    /**
     * La même demande répétée après une réponse perdue ne renvoie pas de
     * second lien : une seule demande, un seul courriel, une seule entrée
     * d'audit.
     */
    public function test_une_demande_de_transmission_repetee_ne_renvoie_pas_de_second_lien(): void
    {
        Mail::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurPretAReprendre($magasin);

        $premiere = app(DemanderLaTransmissionDeLaProprieteAction::class)->handle($proprietaire, $administrateur, self::MOT_DE_PASSE_DU_PROPRIETAIRE);
        $seconde = app(DemanderLaTransmissionDeLaProprieteAction::class)->handle($proprietaire, $administrateur, self::MOT_DE_PASSE_DU_PROPRIETAIRE);

        $this->assertTrue($premiere->is($seconde));
        Mail::assertSentCount(1);
        $this->assertSame(1, TransmissionPropriete::query()->count());
        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::TransmissionDemandee));
        app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($administrateur, $this->jetonDuDernierLienDeTransmission(), self::MOT_DE_PASSE_CHOISI);
        $this->assertTrue($this->proprietaireDe($magasin)->is($administrateur));
    }

    /**
     * « Renvoyer le lien » envoie un nouveau lien, inscrit au journal
     * d'audit : le premier ne sert plus, le nouveau transmet la propriété.
     */
    public function test_renvoyer_le_lien_rend_le_premier_inutilisable(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurPretAReprendre($magasin);
        $premier = $this->demanderLaTransmission($proprietaire, $administrateur);

        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        Livewire::test(FicheDuMembre::class, ['record' => $administrateur->getRouteKey()])
            ->assertActionHidden('transmettreLaPropriete')
            ->callAction('renvoyerLeLienDeTransmission')
            ->assertHasNoActionErrors();
        $nouveau = $this->jetonDuDernierLienDeTransmission();

        $this->assertNotSame($premier, $nouveau);
        $this->assertSame([false, true], array_map(fn ($entree): bool => $entree->nouvelles_valeurs['lien_renvoye'], $this->entreesDAudit($magasin->societe, ActionAuditee::TransmissionDemandee)));
        $this->assertThrows(fn () => app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($administrateur, $premier, self::MOT_DE_PASSE_CHOISI), LogicException::class, AccepterLaTransmissionDeLaProprieteAction::MESSAGE_LIEN);
        app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($administrateur, $nouveau, self::MOT_DE_PASSE_CHOISI);
        $this->assertTrue($this->proprietaireDe($magasin)->is($administrateur));
    }

    /**
     * Une demande pour un autre administrateur annule celle qui attendait,
     * avec une entrée d'audit : le premier lien ne sert plus.
     */
    public function test_une_demande_pour_un_autre_administrateur_annule_la_precedente(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $premier = $this->administrateurPretAReprendre($magasin, 'premier@example.test');
        $second = $this->administrateurPretAReprendre($magasin, 'second@example.test');
        $jetonDuPremier = $this->demanderLaTransmission($proprietaire, $premier);

        $jetonDuSecond = $this->demanderLaTransmission($proprietaire, $second);

        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::TransmissionAnnulee));
        $this->assertThrows(fn () => app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($premier, $jetonDuPremier, self::MOT_DE_PASSE_CHOISI), LogicException::class, AccepterLaTransmissionDeLaProprieteAction::MESSAGE_LIEN);
        app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($second, $jetonDuSecond, self::MOT_DE_PASSE_CHOISI);
        $this->assertTrue($this->proprietaireDe($magasin)->is($second));
        $this->assertSame(RoleSociete::Administrateur, $premier->refresh()->role_societe);
    }

    public function test_le_transfert_a_un_administrateur_sans_qualite_prouvee_est_refuse(): void
    {
        Mail::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->renseignerSonAdresse($this->administrateur($magasin, null, AutorisationsSociete::toutes()), 'repreneur@example.test');

        $this->assertThrows(fn () => app(DemanderLaTransmissionDeLaProprieteAction::class)->handle($proprietaire, $administrateur, self::MOT_DE_PASSE_DU_PROPRIETAIRE), LogicException::class, DemanderLaTransmissionDeLaProprieteAction::MESSAGE_QUALITE);

        Mail::assertNothingSent();
        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
        $this->assertSame(RoleSociete::Administrateur, $administrateur->refresh()->role_societe);
        $this->assertSame([], $this->entreesDAudit($magasin->societe, ActionAuditee::TransmissionDemandee));
    }

    /**
     * Le mot de passe du propriétaire se redonne à chaque demande : sans
     * lui, ou faux, aucun lien ne part.
     */
    public function test_le_mot_de_passe_est_redemande(): void
    {
        Mail::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurPretAReprendre($magasin);

        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        Livewire::test(FicheDuMembre::class, ['record' => $administrateur->getRouteKey()])
            ->callAction('transmettreLaPropriete', ['mot_de_passe' => ''])
            ->assertHasActionErrors(['mot_de_passe' => 'required']);
        Livewire::test(FicheDuMembre::class, ['record' => $administrateur->getRouteKey()])
            ->callAction('transmettreLaPropriete', ['mot_de_passe' => 'devine-au-hasard'])
            ->assertNotified(MotDePasseRedemande::MESSAGE);

        Mail::assertNothingSent();
        $this->assertSame(0, TransmissionPropriete::query()->count());
        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
    }

    /**
     * Après une transmission réussie comme après chaque demande refusée, la
     * société a exactement un propriétaire actif ; la base refuse d'en
     * poser un second.
     */
    public function test_chaque_societe_a_exactement_un_proprietaire_actif_apres_chaque_action_et_procedure(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = tap($this->proprietaireDe($magasin)->forceFill(['qualite_verifiee_le' => now()]))->save();
        $premier = $this->administrateurPretAReprendre($magasin, 'premier@example.test');
        $sansQualite = $this->renseignerSonAdresse($this->administrateur($magasin, null, AutorisationsSociete::toutes()), 'sans-qualite@example.test');
        $unSeulProprietaireActif = fn (): bool => User::query()->where('societe_id', $magasin->societe_id)->where('role_societe', RoleSociete::Proprietaire->value)->where('statut', 'actif')->count() === 1;

        $this->assertThrows(fn () => app(DemanderLaTransmissionDeLaProprieteAction::class)->handle($proprietaire, $sansQualite, self::MOT_DE_PASSE_DU_PROPRIETAIRE), LogicException::class);
        $this->assertTrue($unSeulProprietaireActif());
        $this->transmettre($proprietaire, $premier);
        $this->assertTrue($unSeulProprietaireActif());
        $this->assertThrows(fn () => app(DemanderLaTransmissionDeLaProprieteAction::class)->handle($proprietaire->refresh(), $sansQualite, self::MOT_DE_PASSE_DU_PROPRIETAIRE), AuthorizationException::class, DemanderLaTransmissionDeLaProprieteAction::MESSAGE_PROPRIETAIRE_SEUL);
        $this->transmettre($premier->refresh(), $proprietaire->refresh(), self::MOT_DE_PASSE_CHOISI, self::MOT_DE_PASSE_DU_PROPRIETAIRE);
        $this->assertTrue($unSeulProprietaireActif());
        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));

        $retire = $this->membre($magasin, RoleMagasin::Vendeur, null, ['email' => 'retire@example.test']);
        app(ChangerStatutCompteAction::class)->retirer($proprietaire->refresh(), $retire);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('users')->where('id', $retire->id)->update(['role_societe' => RoleSociete::Proprietaire->value])), UniqueConstraintViolationException::class);
        $this->assertTrue($unSeulProprietaireActif());
    }

    public function test_un_administrateur_ne_transmet_pas_la_propriete(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurPretAReprendre($magasin, 'premier@example.test');
        $autre = $this->administrateurPretAReprendre($magasin, 'autre@example.test');

        $this->assertThrows(fn () => app(DemanderLaTransmissionDeLaProprieteAction::class)->handle($administrateur, $autre, self::MOT_DE_PASSE_CHOISI), AuthorizationException::class, DemanderLaTransmissionDeLaProprieteAction::MESSAGE_PROPRIETAIRE_SEUL);
        $this->assertThrows(fn () => app(DemanderLaTransmissionDeLaProprieteAction::class)->handle($administrateur, $administrateur, self::MOT_DE_PASSE_CHOISI), AuthorizationException::class, DemanderLaTransmissionDeLaProprieteAction::MESSAGE_PROPRIETAIRE_SEUL);
        $this->assertThrows(fn () => app(AnnulerLaTransmissionDeLaProprieteAction::class)->handle($administrateur, $autre), AuthorizationException::class, DemanderLaTransmissionDeLaProprieteAction::MESSAGE_PROPRIETAIRE_SEUL);

        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
        $this->assertSame([RoleSociete::Administrateur, RoleSociete::Administrateur], [$administrateur->refresh()->role_societe, $autre->refresh()->role_societe]);
        $this->assertSame(0, TransmissionPropriete::query()->count());
    }

    /**
     * La propriété ne va qu'à un administrateur actif de la société : ni à
     * l'administrateur d'une autre société, ni à un administrateur
     * suspendu, ni à un membre, même responsable, ni au propriétaire
     * lui-même.
     */
    public function test_la_propriete_ne_va_ni_a_un_compte_d_une_autre_societe_ni_a_un_compte_suspendu_ni_a_un_simple_membre(): void
    {
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Ailleurs']);
        $administrateurAilleurs = $this->administrateurPretAReprendre($ailleurs, 'ailleurs@example.test');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $suspendu = $this->administrateurPretAReprendre($magasin, 'suspendu@example.test');
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $suspendu);
        $responsable = tap($this->renseignerSonAdresse($this->responsable($magasin), 'responsable@example.test')->forceFill(['qualite_verifiee_le' => now()]))->save();

        foreach ([$administrateurAilleurs, $suspendu->refresh(), $responsable, $proprietaire] as $cible) {
            $this->assertThrows(fn () => app(DemanderLaTransmissionDeLaProprieteAction::class)->handle($proprietaire, $cible, self::MOT_DE_PASSE_DU_PROPRIETAIRE), AuthorizationException::class);
        }

        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
        $this->assertSame(RoleSociete::Administrateur, $administrateurAilleurs->refresh()->role_societe);
        $this->assertSame(RoleSociete::Membre, $responsable->refresh()->role_societe);
        $this->assertSame(0, TransmissionPropriete::withoutGlobalScopes()->count());
    }

    /**
     * Le nouveau propriétaire perd sa sélection, ses cases et ses
     * autorisations de société ; l'ancien reçoit tous les magasins,
     * présents et futurs, toutes les cases sauf celles de la vente, celles
     * qu'il avait comme propriétaire (précision 1), et toutes les
     * autorisations de société.
     */
    public function test_le_nouveau_proprietaire_perd_ses_affectations_et_l_ancien_devient_administrateur_de_tous_les_magasins(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = tap($this->renseignerSonAdresse($this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::RESPONSABLES_NOMMER], [PermissionRegistry::FACTURE_VIEW]), 'repreneur@example.test')->forceFill(['qualite_verifiee_le' => now()]))->save();

        $this->transmettre($proprietaire, $administrateur);

        $this->assertSame(0, Affectation::query()->where('user_id', $administrateur->id)->count());
        $this->assertSame(0, DB::table('autorisations_societe')->where('user_id', $administrateur->id)->count());
        $globale = Affectation::query()->where('user_id', $proprietaire->id)->sole();
        $this->assertNull($globale->magasin_id);
        $this->assertNull($globale->role);
        $this->assertEqualsCanonicalizing(PermissionRegistry::duProprietaire(), $globale->autorisations());
        $proprietaire->refresh();
        $this->assertEqualsCanonicalizing(AutorisationsSociete::toutes(), $proprietaire->autorisationsDeSociete());
        $this->assertTrue($proprietaire->administreTousLesMagasins());

        $futur = app(TenantContext::class)->runAs($magasin, fn (): Magasin => $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Magasin futur']));
        $this->assertTrue($proprietaire->refresh()->peutOuvrir($futur));
        $this->assertEqualsCanonicalizing(PermissionRegistry::duProprietaire(), $proprietaire->autorisationsDans($futur));
    }

    /**
     * L'ancien propriétaire, devenu administrateur, ne vend pas plus
     * qu'avant, dans aucun magasin : ni caisse, ni facture, ni caisse
     * personnelle ; le nouveau propriétaire peut lui cocher la vente.
     */
    public function test_l_ancien_proprietaire_devenu_administrateur_n_a_aucune_autorisation_de_vente(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurPretAReprendre($magasin);

        $this->transmettre($proprietaire, $administrateur);

        $ancien = $proprietaire->refresh();
        foreach ([$magasin, $autre] as $chacun) {
            $this->assertSame([], array_values(array_intersect(PermissionRegistry::deVente(), $ancien->autorisationsDans($chacun))), $chacun->nom);
            $this->assertFalse($ancien->tientUneCaisseDans($chacun), $chacun->nom);
        }
        $this->assertThrows(fn () => app(CreerFactureAction::class)->handle($ancien, Client::factory()->create(), $this->lignes()), AuthorizationException::class, LecteurDesDroits::REFUS);
        $this->assertSame(0, Facture::query()->count());
        $this->dansLeMagasin($magasin, $ancien);
        $this->get(Caisse::getUrl(tenant: $magasin))->assertForbidden();

        app(ModifierAdministrateurAction::class)->modifier($administrateur->refresh(), $ancien, null, PermissionRegistry::toutes(), AutorisationsSociete::toutes());

        $this->assertEqualsCanonicalizing(PermissionRegistry::toutes(), $ancien->refresh()->autorisationsDans($autre));
        $this->assertTrue($ancien->tientUneCaisseDans($autre));
    }

    /**
     * Un administrateur à qui le propriétaire avait coché la vente a vendu
     * ce jour-là, puis reçoit la propriété : il ne vend plus, sa caisse
     * reste ouverte, et la clôture du jour la ferme d'office, comme celle
     * de tout vendeur, avec sa vente.
     */
    public function test_le_nouveau_proprietaire_qui_avait_vendu_voit_sa_caisse_fermee_par_la_cloture_du_jour(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->administrateurPretAReprendre($magasin, 'repreneur@example.test', PermissionRegistry::toutes());
        self::vendreAuComptant($vendeur, Client::factory()->create(), $this->lignes(), ModePaiement::Especes);

        $this->transmettre($proprietaire, $vendeur);

        $nouveau = $vendeur->refresh();
        $this->assertFalse($nouveau->tientUneCaisseDans($magasin));
        $this->assertThrows(fn () => self::vendreAuComptant($nouveau, Client::factory()->create(), $this->lignes(), ModePaiement::Especes), AuthorizationException::class, LecteurDesDroits::REFUS);
        $this->assertSame([$nouveau->id], app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(today())->modelKeys());

        $this->dansLeMagasin($magasin, $nouveau);
        Livewire::test(ListClotureCaisses::class)->callAction('cloturer')->assertHasNoActionErrors();

        $releve = ClotureCaissePersonnelle::query()->sole();
        $this->assertSame([$nouveau->id, ModeFermetureCaisse::Automatique, 1, '12.00'], [$releve->vendeur_id, $releve->mode_fermeture, $releve->nombre_ventes, $releve->total_ttc]);
        $this->assertSame([], app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(today())->all());
    }

    /**
     * Un administrateur à qui le propriétaire avait coché la vente a vendu
     * ce jour-là à Ma Boutique, puis reçoit la propriété. Tant que cette
     * caisse reste ouverte, il ne contre-passe rien dans un autre magasin
     * (une caisse à la fois) ; il la ferme lui-même, par « Fermer ma
     * caisse », bien qu'il ne vende plus, puis contre-passe dans l'autre
     * magasin, où la contre-passation revient à la caisse du vendeur du
     * paiement corrigé, jamais à la sienne.
     */
    public function test_le_nouveau_proprietaire_ferme_lui_meme_sa_caisse_puis_contre_passe_dans_un_autre_magasin(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->administrateurPretAReprendre($magasin, 'repreneur@example.test', PermissionRegistry::toutes());
        app(TenantContext::class)->runAs($magasin, fn (): Facture => self::vendreAuComptant($vendeur, Client::factory()->create(), $this->lignes(), ModePaiement::Especes));
        $responsableAilleurs = self::responsableDeTest($autre);
        $paiement = app(TenantContext::class)->runAs($autre, function () use ($responsableAilleurs): Paiement {
            self::vendreAuComptant($responsableAilleurs, Client::factory()->create(), $this->lignes(), ModePaiement::Especes);

            return Paiement::query()->sole();
        });

        $this->transmettre($proprietaire, $vendeur);

        $nouveau = $vendeur->refresh();
        $contrePasser = fn (): Paiement => app(TenantContext::class)->runAs($autre, fn (): Paiement => app(ContrePasserPaiementAction::class)->handle($nouveau, $paiement, 'Saisi par erreur'));
        $this->assertThrows($contrePasser, LogicException::class, 'Fermez d\'abord votre caisse de Ma Boutique : vous n\'encaissez que dans un magasin à la fois.');

        $this->dansLeMagasin($magasin, $nouveau);
        Livewire::test(ListClotureCaissePersonnelles::class)
            ->assertActionVisible('fermer_ma_caisse')
            ->callAction('fermer_ma_caisse')
            ->assertHasNoActionErrors();

        $releve = ClotureCaissePersonnelle::query()->sole();
        $this->assertSame([$nouveau->id, ModeFermetureCaisse::Vendeur, $nouveau->id, 1, '12.00'], [$releve->vendeur_id, $releve->mode_fermeture, $releve->fermee_par, $releve->nombre_ventes, $releve->total_ttc]);
        Livewire::test(ListClotureCaissePersonnelles::class)->assertActionHidden('fermer_ma_caisse');

        $contrePassation = $contrePasser();

        $this->assertSame([$nouveau->id, $paiement->id, '-12.00'], [$contrePassation->cree_par, $contrePassation->contre_passe_paiement_id, $contrePassation->montant]);
        $this->assertSame([$responsableAilleurs->id], app(TenantContext::class)->runAs($autre, fn (): array => app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(today())->modelKeys()));
        $this->assertSame([], app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(today())->all());
    }

    /**
     * Un administrateur à qui le propriétaire avait coché la vente émet un
     * ticket resté dû et crée un brouillon, puis reçoit la propriété. Le
     * brouillon reste à son nom et se modifie toujours, mais la base refuse
     * de l'émettre à son nom ; émis par le responsable, il devient la vente
     * de celui-ci (auteur, nom figé, contenu signé, caisse personnelle), et
     * la caisse du propriétaire ne garde que la vente qu'il avait faite
     * comme administrateur, qui reste la sienne et se règle toujours.
     */
    public function test_un_brouillon_de_l_administrateur_devenu_proprietaire_devient_a_son_emission_la_vente_de_qui_l_emet(): void
    {
        $this->travelTo('2026-10-09 10:00:00');
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->administrateurPretAReprendre($magasin, 'repreneur@example.test', PermissionRegistry::toutes());
        $responsable = self::responsableDeTest($magasin);
        $ticketDu = app(EmettreFactureAction::class)->handle($vendeur, app(CreerFactureAction::class)->handle($vendeur, Client::factory()->create(), $this->lignes()));
        $brouillon = app(CreerFactureAction::class)->handle($vendeur, Client::factory()->create(), $this->lignes());

        $this->transmettre($proprietaire, $vendeur);

        $nouveau = $vendeur->refresh();
        app(ModifierFactureAction::class)->handle($responsable, $brouillon->fresh(), $brouillon->client, $this->lignes());
        $this->assertSame([$nouveau->id, StatutFacture::Brouillon], [$brouillon->fresh()->cree_par, $brouillon->fresh()->statut]);
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('factures')->where('id', $brouillon->id)->update(['numero' => 'TIC-TEST-2026-000099', 'statut' => StatutFacture::Emise->value])), QueryException::class, GardesDeLaVente::MESSAGE);

        $vente = app(EmettreFactureAction::class)->handle($responsable, $brouillon->fresh());

        $enregistrement = $vente->enregistrementDeVente()->firstOrFail();
        $this->assertSame([$responsable->id, 'Responsable de test', StatutFacture::Emise], [$vente->cree_par, $vente->cree_par_nom, $vente->statut]);
        $this->assertSame([$responsable->id, $responsable->id], [$enregistrement->operateur_id, $enregistrement->contenuSigne()['donnees']['document']['cree_par']]);
        $this->assertSame([1, 1], [app(FermerSaCaisseAction::class)->apercu($nouveau)->nombre_ventes, app(FermerSaCaisseAction::class)->apercu($responsable)->nombre_ventes]);
        $this->assertEqualsCanonicalizing([$nouveau->id, $responsable->id], app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(today())->modelKeys());

        app(EnregistrerPaiementAction::class)->handle($responsable, $ticketDu->client, ['mode_paiement' => ModePaiement::Especes, 'montant' => 12, 'recu_le' => today()->toDateString()], [['facture_id' => $ticketDu->id, 'montant' => 12]]);

        $this->assertSame([$nouveau->id, StatutFacture::Payee], [$ticketDu->fresh()->cree_par, $ticketDu->fresh()->statut]);
    }

    /**
     * Une requête Livewire forgée par un administrateur, sur la fiche d'un
     * compte qu'il voit, ne demande rien : l'action lui est masquée, et
     * l'action du domaine le refuse.
     */
    public function test_un_transfert_force_par_une_requete_livewire_d_un_administrateur_ne_change_rien(): void
    {
        Mail::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateurPretAReprendre($magasin);
        $responsable = tap($this->renseignerSonAdresse($this->responsable($magasin), 'responsable@example.test')->forceFill(['qualite_verifiee_le' => now()]))->save();

        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        $this->forgerLAction(Livewire::test(FicheDuMembre::class, ['record' => $responsable->getRouteKey()])->assertActionHidden('transmettreLaPropriete'), 'transmettreLaPropriete', ['mot_de_passe' => self::MOT_DE_PASSE_CHOISI]);

        Mail::assertNothingSent();
        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
        $this->assertSame(RoleSociete::Administrateur, $administrateur->refresh()->role_societe);
        $this->assertSame([], $this->entreesDAudit($magasin->societe, ActionAuditee::TransmissionDemandee));
    }

    public function test_la_propriete_ne_va_jamais_a_l_expert_comptable(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $expert = tap($this->renseignerSonAdresse($this->expertComptable($magasin), 'cabinet@example.test')->forceFill(['qualite_verifiee_le' => now()]))->save();

        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        Livewire::test(FicheDuMembre::class, ['record' => $expert->getRouteKey()])->assertActionHidden('transmettreLaPropriete');
        $this->assertThrows(fn () => app(DemanderLaTransmissionDeLaProprieteAction::class)->handle($proprietaire, $expert, self::MOT_DE_PASSE_DU_PROPRIETAIRE), AuthorizationException::class, DemanderLaTransmissionDeLaProprieteAction::MESSAGE_ADMINISTRATEUR);

        $this->assertSame(RoleSociete::ExpertComptable, $expert->refresh()->role_societe);
        $this->assertTrue($this->proprietaireDe($magasin)->is($proprietaire));
    }

    /**
     * La transmission entière, comme dans le vrai parcours : le
     * propriétaire la demande, puis l'administrateur suit le lien reçu et
     * la confirme.
     */
    private function transmettre(User $proprietaire, User $administrateur, string $motDePasseDuProprietaire = self::MOT_DE_PASSE_DU_PROPRIETAIRE, string $motDePasseDeLAdministrateur = self::MOT_DE_PASSE_CHOISI): void
    {
        $jeton = $this->demanderLaTransmission($proprietaire, $administrateur, $motDePasseDuProprietaire);

        app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($administrateur->refresh(), $jeton, $motDePasseDeLAdministrateur);
    }

    /**
     * L'administrateur, connecté, confirme la transmission sur la page du
     * lien.
     */
    private function accepter(User $administrateur, string $jeton): void
    {
        $this->flushSession();
        $this->actingAs($administrateur);
        Filament::setCurrentPanel(Filament::getPanel('societe'));
        Livewire::test(AccepterLaTransmission::class, ['jeton' => $jeton])
            ->fillForm(['mot_de_passe' => self::MOT_DE_PASSE_CHOISI])
            ->call('accepter')
            ->assertHasNoFormErrors()
            ->assertNotified('Vous êtes désormais le propriétaire de la société.');
    }

    /**
     * Une connexion à l'espace de la société, sans session ouverte.
     */
    private function seConnecter(string $saisie, string $motDePasse): Testable
    {
        $this->flushSession();
        auth()->logout();
        Filament::setCurrentPanel(Filament::getPanel('societe'));

        return Livewire::test(Connexion::class)->fillForm(['login' => $saisie, 'password' => $motDePasse])->call('authenticate');
    }

    /**
     * Une ligne d'un article à 12,00 € TTC, sans suivi de stock.
     *
     * @return list<array{produit_id: int, quantite: int}>
     */
    private function lignes(): array
    {
        return [['produit_id' => Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false])->id, 'quantite' => 1]];
    }
}
