<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\ChangerSonMotDePasseAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\EtatDuMotDePasse;
use App\Domain\Tenancy\Enums\ModeDAccesRedonne;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Notifications\AvisDeLEquipe;
use App\Domain\Tenancy\Services\MotsDePasseProvisoires;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\PostesConnus;
use App\Domain\Tenancy\Services\PostesReconnus;
use App\Domain\Tenancy\Support\MotDePasseProvisoire;
use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Filament\App\Pages\Auth\Connexion;
use App\Filament\App\Resources\EquipeDuMagasinResource\Pages\ListEquipeDuMagasin;
use App\Filament\Societe\Resources\MembreResource\Pages\FicheDuMembre;
use App\Filament\Societe\Resources\MembreResource\Pages\ListMembres;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Un accès perdu se redonne (voir RedonnerLAccesAction et
 * PorteeDesDroits::peutRedonnerLAcces()) : par le propriétaire pour tout
 * compte ; par un responsable de son magasin pour un vendeur ou un
 * observateur ; par un administrateur autorisé à nommer les responsables
 * pour un responsable d'un magasin de sa portée ; par un administrateur
 * autorisé à redonner l'accès pour un vendeur ou un observateur d'un magasin
 * de sa portée ; personne ne redonne celui du propriétaire. La
 * personne présente tape elle-même son nouveau mot de passe ; absente, elle
 * reçoit un mot de passe provisoire tiré au hasard, qui ne sert qu'une fois
 * et qu'elle remplace aussitôt par le sien. Chaque renouvellement ferme les sessions,
 * s'inscrit au journal d'audit, s'affiche à la personne et, fait par un
 * autre, est signalé au propriétaire.
 */
class RenouvellementDAccesTest extends TestCase
{
    use RefreshDatabase;

    private const NOUVEAU_MOT_DE_PASSE = 'un-mot-de-passe-solide';

    public function test_le_responsable_redonne_l_acces_d_un_vendeur_de_son_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $saLigne = Affectation::query()->where('user_id', $vendeur->id)->sole();

        $this->dansLeMagasin($magasin, $responsable);
        Livewire::test(ListEquipeDuMagasin::class)
            ->callTableAction('redonner_l_acces', $saLigne, ['mode' => 'absent'])
            ->assertHasNoTableActionErrors();

        $provisoire = $this->motDePasseProvisoireNotifie();
        $vendeur->refresh();
        $this->assertTrue(Hash::check($provisoire, $vendeur->password));
        $this->assertFalse(Hash::check((string) $vendeur->identifiantDeConnexion(), $vendeur->password), 'L\'identifiant n\'est jamais un mot de passe.');
        $this->assertSame([EtatDuMotDePasse::ProvisoireValable, ModeDAccesRedonne::Absence, (int) $responsable->id], [$vendeur->etatDuMotDePasse(), $vendeur->acces_redonne_mode, (int) $vendeur->acces_redonne_par]);
    }

    public function test_un_responsable_ne_redonne_l_acces_ni_d_un_autre_responsable_ni_d_un_compte_d_un_autre_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $responsable = $this->responsable($magasin);
        $collegue = $this->responsable($magasin);
        $vendeurDAilleurs = $this->membre($autre, RoleMagasin::Vendeur);

        foreach ([$collegue, $vendeurDAilleurs] as $cible) {
            $this->assertFalse($responsable->can('redonnerLAcces', $cible), $cible->name);
            $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->enSonAbsence($responsable, $cible), AuthorizationException::class);
            $this->assertNull($cible->refresh()->acces_redonne_le);
        }
    }

    public function test_un_administrateur_autorise_a_nommer_les_responsables_redonne_l_acces_d_un_responsable_de_sa_portee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $horsPortee = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $responsable = $this->responsable($magasin);
        $responsableHorsPortee = $this->responsable($horsPortee);

        $this->assertTrue(app(RedonnerLAccesAction::class)->enSaPresence($administrateur, $responsable, self::NOUVEAU_MOT_DE_PASSE, (string) Str::uuid()));

        $this->assertTrue(Hash::check(self::NOUVEAU_MOT_DE_PASSE, $responsable->refresh()->password));
        $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->enSonAbsence($administrateur, $responsableHorsPortee), AuthorizationException::class);
        $this->assertNull($responsableHorsPortee->refresh()->acces_redonne_le);
    }

    /**
     * Autorisé par le propriétaire à redonner l'accès, un administrateur le
     * fait pour un vendeur d'un magasin de sa portée, et rien d'autre sur ce
     * compte : ni ses cases, ni son rôle, ni sa suspension, ni son retrait ;
     * rien pour un vendeur d'un magasin hors de sa portée.
     */
    public function test_un_administrateur_autorise_par_le_proprietaire_redonne_l_acces_d_un_vendeur_de_sa_portee_et_ne_fait_rien_d_autre_sur_ce_compte(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $horsPortee = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::ACCES_REDONNER], self::toutesCellesDUnCompteDeLaSociete());
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $vendeurHorsPortee = $this->membre($horsPortee, RoleMagasin::Vendeur);

        $this->assertInstanceOf(MotDePasseProvisoire::class, app(RedonnerLAccesAction::class)->enSonAbsence($administrateur, $vendeur));
        $this->assertFalse($vendeur->refresh()->aChoisiSonMotDePasse());

        foreach (['update', 'suspendreOuReactiver', 'retirerDeLaSociete', 'changerLeRoleDeSociete'] as $geste) {
            $this->assertFalse($administrateur->can($geste, $vendeur), $geste);
        }
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($administrateur, $vendeur, RoleMagasin::Observateur), AuthorizationException::class);
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->suspendre($administrateur, $vendeur), AuthorizationException::class);
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->retirer($administrateur, $vendeur), AuthorizationException::class);
        $this->assertSame([RoleMagasin::Vendeur, true], [Affectation::query()->where('user_id', $vendeur->id)->sole()->role, $vendeur->refresh()->estActif()]);
        $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->enSonAbsence($administrateur, $vendeurHorsPortee), AuthorizationException::class);
    }

    /**
     * Dans l'espace de la société, un administrateur qui n'a que « redonner
     * l'accès » voit les vendeurs et les observateurs des magasins de
     * sa portée, et aucun autre compte ; sur leur fiche, seuls « Redonner
     * l'accès » et « Afficher l'identifiant » ; ni identifiant ni badge en
     * liste.
     */
    public function test_un_administrateur_qui_n_a_que_redonner_l_acces_voit_les_vendeurs_de_sa_portee_et_rien_d_autre(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $horsPortee = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Autre Boutique']);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::ACCES_REDONNER]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $observateur = $this->membre($magasin, RoleMagasin::Observateur);
        $invisibles = [
            $this->responsable($magasin),
            $this->membre($horsPortee, RoleMagasin::Vendeur),
            $this->administrateur($magasin),
            $this->collaborateurDuCabinetLie($magasin),
            $this->proprietaireDe($magasin),
        ];

        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        Livewire::test(ListMembres::class)
            ->assertCanSeeTableRecords([$vendeur, $observateur])
            ->assertCanNotSeeTableRecords($invisibles)
            ->assertTableColumnHidden('identifiant')
            ->assertTableColumnHidden('signaux')
            ->assertActionHidden('creerUnMembre')
            ->assertActionHidden('nommerUnResponsable')
            ->assertActionHidden('creerUnAdministrateur');

        $fiche = Livewire::test(FicheDuMembre::class, ['record' => $vendeur->getRouteKey()]);
        $fiche->assertActionVisible('redonnerLAcces')->assertActionVisible('afficherLIdentifiant')->assertDontSee((string) $vendeur->identifiantDeConnexion());
        foreach (['modifierSonRole', 'changerLeRoleDeSociete', 'suspendre', 'reactiver', 'retirerDeLaSociete', 'reintegrer', 'annulerLaNomination'] as $geste) {
            $fiche->assertActionHidden($geste);
        }
    }

    /**
     * Le même administrateur ne crée ni ne modifie aucun compte, même par
     * des requêtes Livewire forgées : ni création, ni cases, ni suspension,
     * ni retrait.
     */
    public function test_il_ne_cree_aucun_compte_meme_par_une_action_livewire_forgee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::ACCES_REDONNER], self::toutesCellesDUnCompteDeLaSociete());
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);
        $comptes = User::query()->count();

        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        $this->forgerLAction(Livewire::test(ListMembres::class), 'creerUnMembre', ['jeton' => (string) Str::uuid(), 'magasin_id' => $magasin->id, 'name' => 'Intrus', 'role' => RoleMagasin::Vendeur->value]);
        $this->forgerLAction(Livewire::test(ListMembres::class), 'nommerUnResponsable', ['magasin_id' => $magasin->id, 'compte' => 'nouveau', 'name' => 'Intrus']);
        $this->forgerLAction(Livewire::test(FicheDuMembre::class, ['record' => $vendeur->getRouteKey()]), 'modifierSonRole', ['role' => RoleMagasin::Vendeur->value, 'autorisations' => self::toutesCellesDUnCompteDeLaSociete()]);
        $this->forgerLAction(Livewire::test(FicheDuMembre::class, ['record' => $vendeur->getRouteKey()]), 'suspendre');
        $this->forgerLAction(Livewire::test(FicheDuMembre::class, ['record' => $vendeur->getRouteKey()]), 'retirerDeLaSociete');

        $this->assertSame($comptes, User::query()->count());
        $this->assertFalse(User::query()->where('name', 'Intrus')->exists());
        $this->assertTrue($vendeur->refresh()->estActif());
        $this->assertSame([PermissionRegistry::FACTURE_VIEW], $vendeur->autorisationsDans($magasin));
    }

    /**
     * Un administrateur ne voit l'identifiant d'un vendeur que par
     * « Afficher l'identifiant » : le geste s'inscrit au journal d'audit, le
     * compte sans sa valeur. L'identifiant n'est jamais un mot de passe : le
     * voir n'ouvre aucun compte, et le propriétaire n'en reçoit aucun avis,
     * que le compte ait ou non choisi son mot de passe.
     */
    public function test_un_administrateur_ne_voit_l_identifiant_que_par_une_action_tracee_sans_prevenir_le_proprietaire(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::ACCES_REDONNER]);
        [$vendeur] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);
        $identifiant = (string) $vendeur->identifiantDeConnexion();
        $prudent = $this->membre($magasin, RoleMagasin::Vendeur);

        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        Livewire::test(FicheDuMembre::class, ['record' => $vendeur->getRouteKey()])
            ->assertDontSee($identifiant)
            ->callAction('afficherLIdentifiant')
            ->assertNotified("Identifiant de {$vendeur->name} : {$identifiant}");

        $entrees = $this->entreesDAudit($magasin->societe, ActionAuditee::IdentifiantConsulte);
        $this->assertCount(1, $entrees);
        $this->assertSame((int) $vendeur->id, (int) $entrees[0]->nouvelles_valeurs['compte']['id']);
        $this->assertStringNotContainsString($identifiant, json_encode($entrees[0]->toArray(), JSON_THROW_ON_ERROR));
        Notification::assertNothingSentTo($proprietaire);

        Livewire::test(FicheDuMembre::class, ['record' => $prudent->getRouteKey()])->callAction('afficherLIdentifiant');
        Notification::assertNothingSentTo($proprietaire);
        $this->assertCount(2, $this->entreesDAudit($magasin->societe, ActionAuditee::IdentifiantConsulte));
    }

    /**
     * La fenêtre « Compte créé » ne montre que le compte que la page vient
     * de créer : un administrateur autorisé à redonner l'accès et à nommer
     * les responsables qui la demande, par une requête forgée, pour un
     * vendeur ou un responsable de sa portée, ne la voit pas s'ouvrir et ne
     * voit aucun identifiant, et le compte qu'elle retient ne s'écrit pas
     * depuis le navigateur. Le compte de responsable qu'il crée lui-même,
     * lui, s'y montre.
     */
    public function test_la_fenetre_du_compte_cree_ne_montre_que_le_compte_que_la_page_vient_de_creer(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::ACCES_REDONNER, AutorisationsSociete::RESPONSABLES_NOMMER]);
        [$vendeur, $provisoireDuVendeur] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);
        [$responsable, $provisoireDuResponsable] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Responsable);
        $identifiantDuVendeur = (string) $vendeur->identifiantDeConnexion();
        $identifiantDuResponsable = (string) $responsable->identifiantDeConnexion();
        Notification::fake();

        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        foreach ([$vendeur, $responsable] as $compte) {
            Livewire::test(ListMembres::class)
                ->call('mountAction', 'compteCree', ['compte' => $compte->id])
                ->assertActionNotMounted('compteCree')
                ->assertDontSee($identifiantDuVendeur)
                ->assertDontSee($identifiantDuResponsable)
                ->assertDontSee($provisoireDuVendeur)
                ->assertDontSee($provisoireDuResponsable);
        }
        $this->assertThrows(fn () => Livewire::test(ListMembres::class)->set('compteCreeId', $vendeur->id), CannotUpdateLockedPropertyException::class);
        $this->assertCount(0, $this->entreesDAudit($magasin->societe, ActionAuditee::IdentifiantConsulte));
        Notification::assertNothingSent();

        $page = Livewire::test(ListMembres::class)
            ->mountAction('nommerUnResponsable')
            ->setActionData(['magasin_id' => $magasin->id, 'compte' => 'nouveau'])
            ->setActionData(['name' => 'Chef Nouveau'])
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertActionMounted('compteCree');
        $chef = User::query()->where('name', 'Chef Nouveau')->sole();
        $page->assertSee("Identifiant : {$chef->identifiantDeConnexion()}")
            ->assertSee('Mot de passe provisoire : '.$page->get('motDePasseProvisoireMontre'))
            ->assertSee('depuis un poste reconnu du magasin, et la personne le remplace aussitôt, à sa première connexion, par un mot de passe à elle')
            ->assertDontSee($identifiantDuVendeur)
            ->assertDontSee($provisoireDuVendeur);
    }

    public function test_un_administrateur_sans_autorisation_ne_redonne_aucun_acces(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::MAGASINS_GERER, AutorisationsSociete::PARAMETRES_GERER], self::toutesCellesDUnCompteDeLaSociete());

        foreach ([$this->membre($magasin, RoleMagasin::Vendeur), $this->membre($magasin, RoleMagasin::Observateur), $this->responsable($magasin)] as $cible) {
            $this->assertFalse($administrateur->can('redonnerLAcces', $cible), $cible->name);
            $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->enSonAbsence($administrateur, $cible), AuthorizationException::class);
        }
    }

    public function test_seul_le_proprietaire_redonne_l_acces_d_un_administrateur(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autreAdministrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes(), self::toutesCellesDUnCompteDeLaSociete());
        $responsable = $this->responsable($magasin);
        $cible = $this->administrateur($magasin);

        foreach ([$autreAdministrateur, $responsable] as $auteur) {
            $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->enSonAbsence($auteur, $cible), AuthorizationException::class);
        }
        $this->assertInstanceOf(MotDePasseProvisoire::class, app(RedonnerLAccesAction::class)->enSonAbsence($this->proprietaireDe($magasin), $cible));
        $this->assertFalse($cible->refresh()->aChoisiSonMotDePasse());
    }

    public function test_personne_ne_redonne_l_acces_du_proprietaire(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $motDePasse = $proprietaire->password;

        foreach ([$proprietaire, $this->administrateur($magasin, null, AutorisationsSociete::toutes(), self::toutesCellesDUnCompteDeLaSociete()), $this->responsable($magasin)] as $auteur) {
            $this->assertFalse($auteur->can('redonnerLAcces', $proprietaire), $auteur->name);
            $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->enSaPresence($auteur, $proprietaire, self::NOUVEAU_MOT_DE_PASSE, (string) Str::uuid()), AuthorizationException::class);
        }

        $this->assertSame([$motDePasse, null], [$proprietaire->refresh()->password, $proprietaire->acces_redonne_le]);
    }

    /**
     * La personne présente tape son nouveau mot de passe, qui suit la règle
     * du mot de passe choisi : ni trop faible (au moins 12 caractères, ou 8
     * de trois sortes), ni un mot de passe courant, ni son identifiant, ni
     * une chaîne qui le contient, ni un seul caractère répété, ni une suite
     * de chiffres.
     */
    public function test_en_presence_la_personne_choisit_son_mot_de_passe_et_il_suit_la_regle(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $identifiant = (string) $vendeur->identifiantDeConnexion();
        $refuses = [
            'court7c' => RegleDuMotDePasse::MESSAGE_TROP_FAIBLE,
            'motdepasse2026' => RegleDuMotDePasse::MESSAGE_COURANT,
            $identifiant => RegleDuMotDePasse::MESSAGE_IDENTIFIANT,
            "mon-{$identifiant}!" => RegleDuMotDePasse::MESSAGE_IDENTIFIANT,
            'aaaaaaaaaa' => RegleDuMotDePasse::MESSAGE_REPETE,
            '23456789' => RegleDuMotDePasse::MESSAGE_SUITE,
        ];

        foreach ($refuses as $motDePasse => $message) {
            try {
                app(RedonnerLAccesAction::class)->enSaPresence($this->proprietaireDe($magasin), $vendeur, (string) $motDePasse, (string) Str::uuid());
                $this->fail("Le mot de passe « {$motDePasse} » est accepté.");
            } catch (ValidationException $refus) {
                $this->assertContains($message, $refus->errors()['mot_de_passe'], (string) $motDePasse);
            }
        }
        $this->assertNull($vendeur->refresh()->acces_redonne_le);

        app(RedonnerLAccesAction::class)->enSaPresence($this->proprietaireDe($magasin), $vendeur, self::NOUVEAU_MOT_DE_PASSE, (string) Str::uuid());

        $vendeur->refresh();
        $this->assertTrue(Hash::check(self::NOUVEAU_MOT_DE_PASSE, $vendeur->password));
        $this->assertSame([true, ModeDAccesRedonne::Presence], [$vendeur->aChoisiSonMotDePasse(), $vendeur->acces_redonne_mode]);
    }

    /**
     * La même demande, au même jeton de fenêtre, répétée après une réponse
     * perdue, ne fait rien de plus : une entrée d'audit, un avis au
     * propriétaire, les sessions fermées une seule fois.
     */
    public function test_un_acces_redonne_en_presence_repete_ne_s_inscrit_qu_une_fois(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::ACCES_REDONNER]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $jeton = (string) Str::uuid();

        $this->assertTrue(app(RedonnerLAccesAction::class)->enSaPresence($administrateur, $vendeur, self::NOUVEAU_MOT_DE_PASSE, $jeton));
        $session = $this->ouvrirUneSessionAilleurs($vendeur->refresh());
        $this->assertFalse(app(RedonnerLAccesAction::class)->enSaPresence($administrateur, $vendeur, self::NOUVEAU_MOT_DE_PASSE, $jeton));

        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::AccesRedonne));
        Notification::assertSentToTimes($this->proprietaireDe($magasin), AvisDeLEquipe::class, 1);
        $this->assertTrue(DB::table('sessions')->where('id', $session)->exists());
        $this->assertTrue(app(RedonnerLAccesAction::class)->enSaPresence($administrateur, $vendeur->refresh(), 'un-autre-mot-de-passe', (string) Str::uuid()));
        $this->assertSessionsFermees($vendeur);
    }

    public function test_en_absence_la_personne_recoit_un_mot_de_passe_provisoire_tire_au_hasard_jamais_son_identifiant(): void
    {
        $this->travelTo(self::aParis('2026-11-10 10:00:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        $provisoire = app(RedonnerLAccesAction::class)->enSonAbsence($this->proprietaireDe($magasin), $vendeur);

        $this->assertInstanceOf(MotDePasseProvisoire::class, $provisoire);
        $vendeur->refresh();
        $this->assertTrue(Hash::check($provisoire->valeur(), $vendeur->password));
        $this->assertFalse(Hash::check((string) $vendeur->identifiantDeConnexion(), $vendeur->password));
        $this->assertFalse(Hash::check(self::MOT_DE_PASSE_CHOISI, $vendeur->password));
        $this->assertSame([EtatDuMotDePasse::ProvisoireValable, ModeDAccesRedonne::Absence, null], [$vendeur->etatDuMotDePasse(), $vendeur->acces_redonne_mode, $vendeur->jeton_acces_redonne]);
        $this->assertSame(self::aParis('2026-11-13 10:00:00')->getTimestamp(), $vendeur->mot_de_passe_provisoire_expire_le?->getTimestamp());
        $this->assertSame(self::aParis('2026-11-13 10:00:00')->getTimestamp(), $provisoire->expireLe->getTimestamp());
    }

    /**
     * Un mot de passe provisoire déjà remis et pas encore utilisé est
     * remplacé par le nouveau (l'ancien ne sert plus), et la même demande,
     * au même jeton, répétée après une réponse perdue, ne tire pas un
     * second mot de passe ; la personne présente ne peut pas garder le mot
     * de passe provisoire remis comme mot de passe choisi.
     */
    public function test_un_mot_de_passe_provisoire_deja_remis_est_remplace_et_ne_se_garde_pas_comme_mot_de_passe_choisi(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        [$vendeur, $premier] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);
        $proprietaire = $this->proprietaireDe($magasin);
        $jeton = (string) Str::uuid();

        $second = ValeurRequise::de(app(RedonnerLAccesAction::class)->enSonAbsence($proprietaire, $vendeur, $jeton), 'le second mot de passe provisoire');
        $this->assertNull(app(RedonnerLAccesAction::class)->enSonAbsence($proprietaire, $vendeur->refresh(), $jeton));

        $vendeur->refresh();
        $this->assertTrue(Hash::check($second->valeur(), $vendeur->password));
        $this->assertFalse(Hash::check($premier, $vendeur->password));
        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::AccesRedonne));

        try {
            app(RedonnerLAccesAction::class)->enSaPresence($proprietaire, $vendeur, $second->valeur(), (string) Str::uuid());
            $this->fail('Le mot de passe provisoire remis est gardé comme mot de passe choisi.');
        } catch (ValidationException $refus) {
            $this->assertSame([MotsDePasseProvisoires::MESSAGE_CELUI_QUI_A_ETE_REMIS], $refus->errors()['mot_de_passe']);
        }

        $this->assertTrue(app(RedonnerLAccesAction::class)->enSaPresence($proprietaire, $vendeur->refresh(), self::NOUVEAU_MOT_DE_PASSE, (string) Str::uuid()));
        $this->assertTrue($vendeur->refresh()->aChoisiSonMotDePasse());
    }

    public function test_l_acces_d_un_compte_suspendu_ne_se_redonne_pas(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $suspendu = $this->membre($magasin, RoleMagasin::Vendeur);
        $retire = $this->membre($magasin, RoleMagasin::Vendeur);
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $suspendu);
        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $retire);

        foreach ([$suspendu, $retire] as $cible) {
            $this->assertFalse($proprietaire->can('redonnerLAcces', $cible->refresh()), $cible->name);
            $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->enSonAbsence($proprietaire, $cible), LogicException::class, RedonnerLAccesAction::MESSAGE_INACTIF);
            $this->assertNull($cible->refresh()->acces_redonne_le);
        }
    }

    public function test_redonner_l_acces_ferme_les_sessions_coupe_le_rappel_et_s_inscrit_au_journal_d_audit(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul Martin']);
        $this->ouvrirUneSessionAilleurs($vendeur);

        app(RedonnerLAccesAction::class)->enSonAbsence($responsable, $vendeur);

        $this->assertSessionsFermees($vendeur);
        $entrees = $this->entreesDAudit($magasin->societe, ActionAuditee::AccesRedonne);
        $this->assertCount(1, $entrees);
        $this->assertSame((int) $responsable->id, (int) $entrees[0]->auteur_id);
        $this->assertSame(['compte' => ['id' => $vendeur->id, 'nom' => 'Paul Martin'], 'mode' => 'absence'], $entrees[0]->nouvelles_valeurs);
        $this->assertStringNotContainsString((string) $vendeur->identifiantDeConnexion(), json_encode($entrees[0]->toArray(), JSON_THROW_ON_ERROR));
    }

    /**
     * Un accès redonné par un autre que le propriétaire lui est signalé,
     * dans sa cloche et par e-mail ; puis le premier mot de passe que la
     * personne choisit ensuite, une seule fois. Un accès qu'il redonne
     * lui-même ne lui est pas signalé.
     */
    public function test_le_proprietaire_est_prevenu_d_un_acces_redonne_par_un_autre_puis_du_premier_mot_de_passe_choisi_ensuite(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul Martin']);

        app(RedonnerLAccesAction::class)->enSonAbsence($responsable, $vendeur);

        Notification::assertSentTo($proprietaire, AvisDeLEquipe::class, fn (AvisDeLEquipe $avis): bool => $avis->titre === 'Accès redonné'
            && str_contains($avis->texte, "{$responsable->name} a redonné l'accès de Paul Martin, en son absence")
            && $avis->via($proprietaire) === ['database', 'mail']);
        $this->assertTrue($vendeur->refresh()->signaler_le_prochain_mot_de_passe);

        app(ChangerSonMotDePasseAction::class)->handle($vendeur, self::NOUVEAU_MOT_DE_PASSE);
        app(ChangerSonMotDePasseAction::class)->handle($vendeur->refresh(), 'encore-un-autre-mot-de-passe');

        Notification::assertSentToTimes($proprietaire, AvisDeLEquipe::class, 2);
        Notification::assertSentTo($proprietaire, AvisDeLEquipe::class, fn (AvisDeLEquipe $avis): bool => $avis->titre === 'Mot de passe choisi' && str_contains($avis->texte, 'Paul Martin'));
        $this->assertFalse($vendeur->refresh()->signaler_le_prochain_mot_de_passe);

        app(RedonnerLAccesAction::class)->enSonAbsence($proprietaire, $vendeur);
        app(ChangerSonMotDePasseAction::class)->handle($vendeur->refresh(), self::NOUVEAU_MOT_DE_PASSE);
        Notification::assertSentToTimes($proprietaire, AvisDeLEquipe::class, 2);
    }

    /**
     * À sa connexion suivante, la personne voit sa connexion précédente,
     * puis que son accès a été redonné, quand, par qui et en sa présence ;
     * à la connexion d'après, plus rien de l'accès redonné.
     */
    public function test_la_personne_voit_l_acces_redonne_et_sa_derniere_connexion_a_sa_connexion_suivante(): void
    {
        $this->travelTo(self::aParis('2026-10-12 09:15:00'));
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->membre($magasin, RoleMagasin::Responsable, null, ['name' => 'Julie Morel']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $identifiant = (string) $vendeur->identifiantDeConnexion();
        $this->seConnecter($identifiant, self::MOT_DE_PASSE_CHOISI)->assertHasNoErrors();

        $this->travelTo(self::aParis('2026-10-13 18:20:00'));
        app(RedonnerLAccesAction::class)->enSaPresence($responsable, $vendeur->refresh(), self::NOUVEAU_MOT_DE_PASSE, (string) Str::uuid());
        $this->travelTo(self::aParis('2026-10-14 08:05:00'));

        $this->seConnecter($identifiant, self::NOUVEAU_MOT_DE_PASSE)->assertHasNoErrors();
        $this->assertSame(['Votre connexion précédente : le 12/10/2026 à 09:15', 'Votre accès a été redonné le 13/10/2026 à 18:20 par Julie Morel, en votre présence.'], $this->titresDesAvisALaPersonne());

        $this->travelTo(self::aParis('2026-10-15 08:00:00'));
        $this->seConnecter($identifiant, self::NOUVEAU_MOT_DE_PASSE)->assertHasNoErrors();
        $this->assertSame(['Votre connexion précédente : le 14/10/2026 à 08:05'], $this->titresDesAvisALaPersonne());
    }

    /**
     * Les titres des avis que la connexion vient de montrer à la personne,
     * dans l'ordre, lus dans sa session sans les consommer.
     *
     * @return list<string>
     */
    private function titresDesAvisALaPersonne(): array
    {
        return collect(session('filament.notifications', []))->pluck('title')->values()->all();
    }

    /**
     * Une connexion au panneau du magasin, sans session ouverte ni témoin.
     */
    private function seConnecter(string $saisie, string $motDePasse): Testable
    {
        $this->flushSession();
        auth()->logout();
        Filament::setCurrentPanel(Filament::getPanel('app'));

        return Livewire::withCookies([PostesReconnus::TEMOIN => '', PostesConnus::TEMOIN => ''])
            ->test(Connexion::class)
            ->fillForm(['login' => $saisie, 'password' => $motDePasse])
            ->call('authenticate');
    }
}
