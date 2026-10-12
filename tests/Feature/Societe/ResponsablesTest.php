<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\AnnulerUneNominationAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Notifications\ResponsableNommeParUnAdministrateur;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Filament\Societe\Resources\MembreResource;
use App\Filament\Societe\Resources\MembreResource\Pages\FicheDuMembre;
use App\Filament\Societe\Resources\MembreResource\Pages\ListMembres;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Les responsables des magasins : le propriétaire en nomme autant qu'il
 * veut dans chaque magasin, une même personne pouvant l'être de plusieurs
 * magasins ; un administrateur autorisé à « nommer et retirer les
 * responsables » en nomme, retire et déplace dans les seuls magasins de sa
 * portée, sa nomination prenant effet aussitôt, le propriétaire en étant
 * prévenu et pouvant l'annuler en un clic, comme l'administrateur qui l'a
 * faite. Suspendre, réactiver ou rétrograder un responsable reste au
 * propriétaire.
 */
class ResponsablesTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_nomme_plusieurs_responsables_dans_un_magasin_et_une_personne_responsable_de_deux_magasins(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        $premier = app(CreerMembreAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Premier', 'email' => 'premier@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Responsable], $autre->id => ['role' => RoleMagasin::Responsable]]);
        app(ModifierMembreAction::class)->handle($proprietaire, $vendeur, [$magasin->id => ['role' => RoleMagasin::Responsable]]);

        $this->assertEqualsCanonicalizing([$premier->id, $vendeur->id], Affectation::query()->where('magasin_id', $magasin->id)->where('role', RoleMagasin::Responsable->value)->pluck('user_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame([$magasin->id, $autre->id], Affectation::query()->where('user_id', $premier->id)->where('role', RoleMagasin::Responsable->value)->orderBy('magasin_id')->pluck('magasin_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertNull(Affectation::query()->where('user_id', $vendeur->id)->sole()->nomme_par);
    }

    /**
     * Sans l'autorisation, un administrateur, même de tous les magasins et
     * avec toutes les cases, ne nomme aucun responsable : ni par l'écran,
     * ni par une requête forgée, ni par l'action.
     */
    public function test_un_administrateur_sans_l_autorisation_ne_nomme_aucun_responsable(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::MAGASINS_GERER, AutorisationsSociete::PARAMETRES_GERER], PermissionRegistry::toutes());
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        $this->assertFalse($administrateur->can('nommerUnResponsable', $magasin));
        $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($administrateur, $magasin->societe, ['name' => 'Chef', 'email' => 'chef@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Responsable]]), AuthorizationException::class);
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($administrateur, $vendeur, [$magasin->id => ['role' => RoleMagasin::Responsable]]), AuthorizationException::class);
        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        $this->get(MembreResource::getUrl('index', ['tenant' => $magasin->societe]))->assertForbidden();

        $this->assertFalse(User::query()->where('email', 'chef@example.test')->exists());
        $this->assertSame(RoleMagasin::Vendeur, Affectation::query()->where('user_id', $vendeur->id)->sole()->role);
    }

    public function test_un_administrateur_sans_l_autorisation_ne_voit_aucun_compte(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::MAGASINS_GERER]);
        $responsable = $this->responsable($magasin);

        $this->assertSame(0, app(PorteeDesDroits::class)->comptesVisibles($administrateur)->count());
        $this->assertFalse($administrateur->can('viewAny', User::class));
        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        $this->get(MembreResource::getUrl('index', ['tenant' => $magasin->societe]))->assertForbidden();
        $this->get(MembreResource::getUrl('view', ['record' => $responsable, 'tenant' => $magasin->societe]))->assertNotFound();
    }

    public function test_un_administrateur_autorise_nomme_retire_et_change_les_magasins_des_responsables_de_ses_seuls_magasins(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $administrateur = $this->administrateur($magasin, [$magasin->id, $autre->id], [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        $nouveau = app(CreerMembreAction::class)->handle($administrateur, $magasin->societe, ['name' => 'Chef', 'email' => 'chef@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Responsable]]);
        app(ModifierMembreAction::class)->handle($administrateur, $vendeur, [$magasin->id => ['role' => RoleMagasin::Responsable]]);
        app(ModifierMembreAction::class)->handle($administrateur, $nouveau, [$magasin->id => null, $autre->id => ['role' => RoleMagasin::Responsable]]);

        $this->assertSame([[(int) $autre->id, RoleMagasin::Responsable]], Affectation::query()->where('user_id', $nouveau->id)->get()->map(fn (Affectation $affectation): array => [(int) $affectation->magasin_id, $affectation->role])->all());
        $this->assertSame(RoleMagasin::Responsable, Affectation::query()->where('user_id', $vendeur->id)->sole()->role);

        app(ModifierMembreAction::class)->handle($administrateur, $vendeur, [$magasin->id => null]);
        $this->assertSame(StatutCompte::Retire, $vendeur->refresh()->statut);
    }

    public function test_un_administrateur_autorise_d_une_selection_ne_nomme_aucun_responsable_hors_de_sa_selection(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $horsSelection = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Hors sélection']);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $vendeurAilleurs = $this->membre($horsSelection, RoleMagasin::Vendeur);

        $this->assertFalse($administrateur->can('nommerUnResponsable', $horsSelection));
        $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($administrateur, $magasin->societe, ['name' => 'Chef', 'email' => 'chef@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$horsSelection->id => ['role' => RoleMagasin::Responsable]]), AuthorizationException::class);
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($administrateur, $vendeurAilleurs, [$horsSelection->id => ['role' => RoleMagasin::Responsable]]), AuthorizationException::class);

        $this->assertFalse(User::query()->where('email', 'chef@example.test')->exists());
        $this->assertSame(RoleMagasin::Vendeur, Affectation::query()->where('user_id', $vendeurAilleurs->id)->sole()->role);
    }

    /**
     * Un magasin de la société hors de sa portée, forgé dans le formulaire
     * de nomination (qui ne le propose pas), est refusé par l'action, qui
     * revérifie la portée : l'écran le dit, et rien ne s'écrit.
     */
    public function test_un_magasin_hors_de_la_portee_force_dans_la_nomination_d_un_responsable_est_refuse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $horsPortee = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Hors portée']);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::RESPONSABLES_NOMMER]);

        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        Livewire::test(ListMembres::class)
            ->callAction('nommerUnResponsable', ['magasin_id' => $horsPortee->id, 'compte' => 'nouveau', 'name' => 'Chef', 'email' => 'chef@example.test', 'mot_de_passe_provisoire' => self::MOT_DE_PASSE_PROVISOIRE])
            ->assertNotified(PorteeDesDroits::REFUS);
        $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($administrateur, $magasin->societe, ['name' => 'Chef', 'email' => 'chef@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$horsPortee->id => ['role' => RoleMagasin::Responsable]]), AuthorizationException::class);

        $this->assertFalse(User::query()->where('email', 'chef@example.test')->exists());
        $this->assertSame(0, Affectation::query()->where('magasin_id', $horsPortee->id)->count());
    }

    public function test_un_administrateur_autorise_ne_voit_et_ne_nomme_que_les_responsables_de_ses_magasins(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $horsPortee = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Hors portée']);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $responsable = $this->responsable($magasin);
        $responsableAilleurs = $this->responsable($horsPortee);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        Livewire::test(ListMembres::class)
            ->assertCanSeeTableRecords([$responsable])
            ->assertCanNotSeeTableRecords([$responsableAilleurs, $vendeur, $administrateur]);
        $this->get(MembreResource::getUrl('view', ['record' => $responsableAilleurs, 'tenant' => $magasin->societe]))->assertNotFound();
        $this->assertSame([$vendeur->id], app(PorteeDesDroits::class)->candidatsResponsables($administrateur, $magasin)->pluck('users.id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(0, app(PorteeDesDroits::class)->candidatsResponsables($administrateur, $horsPortee)->count());
    }

    public function test_un_administrateur_autorise_ne_nomme_jamais_le_proprietaire_ni_un_administrateur_ni_l_expert_comptable(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $cibles = [$this->proprietaireDe($magasin), $this->administrateur($magasin), $this->expertComptable($magasin), $administrateur];

        foreach ($cibles as $cible) {
            $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($administrateur, $cible, [$magasin->id => ['role' => RoleMagasin::Responsable]]), AuthorizationException::class);
            $this->assertSame(0, Affectation::query()->where('user_id', $cible->id)->whereNotNull('role')->count(), $cible->email);
        }
        $this->assertSame([], array_values(array_intersect(array_map(fn (User $cible): int => $cible->id, $cibles), app(PorteeDesDroits::class)->candidatsResponsables($administrateur, $magasin)->pluck('users.id')->map(fn ($id): int => (int) $id)->all())));
    }

    /**
     * Un compte qui n'a pas encore choisi son mot de passe ne devient
     * responsable que par la personne qui l'a créé : qui connaît le
     * provisoire ne profite jamais de droits qu'il ne pourrait pas donner.
     */
    public function test_un_administrateur_autorise_ne_nomme_un_compte_existant_que_s_il_a_choisi_son_mot_de_passe_ou_s_il_l_a_cree(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        [$auProvisoireDuProprietaire] = $this->compteAuMotDePasseProvisoire($magasin, RoleMagasin::Vendeur);
        $definitif = $this->membre($magasin, RoleMagasin::Vendeur);

        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($administrateur, $auProvisoireDuProprietaire, [$magasin->id => ['role' => RoleMagasin::Responsable]]), AuthorizationException::class);
        app(ModifierMembreAction::class)->handle($administrateur, $definitif, [$magasin->id => ['role' => RoleMagasin::Responsable]]);
        $sonCompte = app(CreerMembreAction::class)->handle($administrateur, $magasin->societe, ['name' => 'Chef', 'email' => 'chef@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Responsable]]);

        $this->assertSame(RoleMagasin::Vendeur, Affectation::query()->where('user_id', $auProvisoireDuProprietaire->id)->sole()->role);
        $this->assertSame(RoleMagasin::Responsable, Affectation::query()->where('user_id', $definitif->id)->sole()->role);
        $this->assertSame(RoleMagasin::Responsable, Affectation::query()->where('user_id', $sonCompte->id)->sole()->role);
        $this->assertNotContains($auProvisoireDuProprietaire->id, app(PorteeDesDroits::class)->candidatsResponsables($administrateur, $magasin)->pluck('users.id')->map(fn ($id): int => (int) $id)->all());
    }

    /**
     * La nomination d'un responsable par un administrateur prend effet
     * aussitôt ; le propriétaire en est prévenu, chaque fois, et pas pour
     * ses propres nominations.
     */
    public function test_le_proprietaire_est_prevenu_de_chaque_responsable_nomme_par_un_administrateur(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        $nouveau = app(CreerMembreAction::class)->handle($administrateur, $magasin->societe, ['name' => 'Chef', 'email' => 'chef@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Responsable]]);
        app(ModifierMembreAction::class)->handle($administrateur, $vendeur, [$magasin->id => ['role' => RoleMagasin::Responsable]]);
        app(CreerMembreAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Par le propriétaire', 'email' => 'parlui@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Responsable]]);

        Notification::assertSentToTimes($proprietaire, ResponsableNommeParUnAdministrateur::class, 2);
        Notification::assertSentTo($proprietaire, ResponsableNommeParUnAdministrateur::class, fn (ResponsableNommeParUnAdministrateur $avis): bool => $avis->responsable->is($nouveau) && $avis->administrateur->is($administrateur) && $avis->magasin->is($magasin));
        Notification::assertNotSentTo($administrateur, ResponsableNommeParUnAdministrateur::class);
    }

    public function test_la_nomination_par_un_administrateur_prend_effet_immediatement(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);

        app(ModifierMembreAction::class)->handle($administrateur, $vendeur, [$magasin->id => ['role' => RoleMagasin::Responsable]]);

        $vendeur->refresh();
        $this->assertTrue($vendeur->estResponsableDe($magasin));
        $this->assertEqualsCanonicalizing(PermissionRegistry::toutes(), $vendeur->autorisationsDans($magasin));
        $affectation = Affectation::query()->where('user_id', $vendeur->id)->sole();
        $this->assertSame([(int) $administrateur->id, ['role' => 'vendeur', 'autorisations' => [PermissionRegistry::FACTURE_VIEW]]], [(int) $affectation->nomme_par, $affectation->avant_la_nomination]);
    }

    /**
     * Le propriétaire annule en un clic, depuis la fiche du compte, la
     * nomination d'un administrateur : le vendeur promu retrouve son rôle et
     * ses cases ; le compte créé par la nomination, qui n'avait que ce
     * magasin, est retiré. Les documents déjà émis ne changent pas.
     */
    public function test_le_proprietaire_annule_une_nomination_en_un_clic(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_CREATE, PermissionRegistry::FACTURE_EMETTRE, PermissionRegistry::PRODUIT_VIEW, PermissionRegistry::CLIENT_VIEW]);
        $nouveau = app(CreerMembreAction::class)->handle($administrateur, $magasin->societe, ['name' => 'Chef', 'email' => 'chef@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Responsable]]);
        app(ModifierMembreAction::class)->handle($administrateur, $vendeur, [$magasin->id => ['role' => RoleMagasin::Responsable]]);
        $vente = $this->venteDe($vendeur->refresh());

        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        Livewire::test(FicheDuMembre::class, ['record' => $vendeur->getRouteKey()])
            ->callAction('annulerLaNomination', ['affectation' => Affectation::query()->where('user_id', $vendeur->id)->sole()->id])
            ->assertHasNoActionErrors();
        app(AnnulerUneNominationAction::class)->handle($proprietaire, Affectation::query()->where('user_id', $nouveau->id)->sole());

        $affectation = Affectation::query()->where('user_id', $vendeur->id)->sole();
        $this->assertSame([RoleMagasin::Vendeur, null, null], [$affectation->role, $affectation->nomme_par, $affectation->avant_la_nomination]);
        $this->assertSame([PermissionRegistry::CLIENT_VIEW, PermissionRegistry::FACTURE_CREATE, PermissionRegistry::FACTURE_EMETTRE, PermissionRegistry::FACTURE_VIEW, PermissionRegistry::PRODUIT_VIEW], $affectation->autorisations());
        $this->assertSame(StatutCompte::Retire, $nouveau->refresh()->statut);
        $this->assertSame(0, Affectation::query()->where('user_id', $nouveau->id)->count());
        $this->assertSame([StatutFacture::Emise, $vente->numero, (int) $vendeur->id], [$vente->fresh()->statut, $vente->fresh()->numero, (int) $vente->fresh()->cree_par]);
        $dernier = collect($this->modificationsDesDroits($magasin))->last();
        $this->assertSame([$nouveau->id, 'responsable', null], [$dernier['membre']['id'], $dernier['avant']['role_magasin'], $dernier['apres']]);
        $this->assertCount(2, $this->entreesDAudit($magasin->societe, ActionAuditee::NominationAnnulee));
    }

    public function test_l_administrateur_auteur_annule_sa_propre_nomination(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        app(ModifierMembreAction::class)->handle($administrateur, $vendeur, [$magasin->id => ['role' => RoleMagasin::Responsable]]);

        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        Livewire::test(FicheDuMembre::class, ['record' => $vendeur->getRouteKey()])
            ->callAction('annulerLaNomination', ['affectation' => Affectation::query()->where('user_id', $vendeur->id)->sole()->id])
            ->assertHasNoActionErrors();

        $this->assertSame(RoleMagasin::Vendeur, Affectation::query()->where('user_id', $vendeur->id)->sole()->role);
    }

    /**
     * Un autre administrateur, même autorisé à nommer les responsables du
     * même magasin, n'annule pas la nomination d'un collègue : le bouton
     * n'apparaît pas, et l'action la refuse.
     */
    public function test_un_autre_administrateur_n_annule_pas_la_nomination(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $auteur = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $collegue = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        app(ModifierMembreAction::class)->handle($auteur, $vendeur, [$magasin->id => ['role' => RoleMagasin::Responsable]]);
        $nomination = Affectation::query()->where('user_id', $vendeur->id)->sole();

        $this->dansLEspaceDeLaSociete($magasin->societe, $collegue);
        $fiche = Livewire::test(FicheDuMembre::class, ['record' => $vendeur->getRouteKey()])->assertActionHidden('annulerLaNomination');
        $this->forgerLAction($fiche, 'annulerLaNomination', ['affectation' => $nomination->id]);
        $this->assertThrows(fn () => app(AnnulerUneNominationAction::class)->handle($collegue, $nomination), AuthorizationException::class);

        $this->assertSame([RoleMagasin::Responsable, (int) $auteur->id], [$nomination->fresh()->role, (int) $nomination->fresh()->nomme_par]);
    }

    /**
     * Appelée directement par qui n'en a pas le droit (un responsable, un
     * vendeur, la personne nommée elle-même, le propriétaire d'une autre
     * société), l'annulation est refusée sans rien changer ; une
     * nomination faite par le propriétaire ne s'annule pas.
     */
    public function test_une_annulation_par_un_appel_direct_sans_droit_est_refusee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $responsable = $this->responsable($magasin);
        $autreVendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        app(ModifierMembreAction::class)->handle($administrateur, $vendeur, [$magasin->id => ['role' => RoleMagasin::Responsable]]);
        $nomination = Affectation::query()->where('user_id', $vendeur->id)->sole();
        $proprietaireDUneAutre = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Autre société']));

        foreach ([$responsable, $autreVendeur, $vendeur, $proprietaireDUneAutre] as $auteur) {
            $this->assertThrows(fn () => app(AnnulerUneNominationAction::class)->handle($auteur, $nomination), AuthorizationException::class);
            $this->assertSame(RoleMagasin::Responsable, $nomination->fresh()->role, $auteur->email);
        }
        $this->assertThrows(fn () => app(AnnulerUneNominationAction::class)->handle($proprietaire, Affectation::query()->where('user_id', $responsable->id)->sole()), AuthorizationException::class);
        $this->assertSame(RoleMagasin::Responsable, Affectation::query()->where('user_id', $responsable->id)->sole()->role);
    }

    public function test_un_administrateur_autorise_ne_retire_d_un_responsable_que_les_magasins_de_sa_portee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $horsPortee = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Hors portée']);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $responsable = $this->responsable($magasin, $horsPortee);

        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($administrateur, $responsable, [$horsPortee->id => null]), AuthorizationException::class);
        app(ModifierMembreAction::class)->handle($administrateur, $responsable, [$magasin->id => null]);

        $this->assertSame([$horsPortee->id], Affectation::query()->where('user_id', $responsable->id)->pluck('magasin_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(StatutCompte::Actif, $responsable->refresh()->statut);
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->retirer($administrateur, $responsable), AuthorizationException::class);
    }

    public function test_un_administrateur_ne_suspend_ni_ne_reactive_ni_ne_retrograde_un_responsable(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $responsable = $this->responsable($magasin);
        $suspendu = $this->responsable($magasin);
        app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDe($magasin), $suspendu);

        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->suspendre($administrateur, $responsable), AuthorizationException::class);
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->reactiver($administrateur, $suspendu), AuthorizationException::class);
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($administrateur, $responsable, [$magasin->id => ['role' => RoleMagasin::Vendeur]]), AuthorizationException::class);

        $this->assertSame([StatutCompte::Actif, StatutCompte::Suspendu], [$responsable->refresh()->statut, $suspendu->refresh()->statut]);
        $this->assertSame(RoleMagasin::Responsable, Affectation::query()->where('user_id', $responsable->id)->sole()->role);
    }

    public function test_retirer_le_dernier_magasin_d_un_responsable_le_retire_de_la_societe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $responsable = $this->responsable($magasin);
        $this->ouvrirUneSessionAilleurs($responsable);

        app(ModifierMembreAction::class)->handle($administrateur, $responsable, [$magasin->id => null]);

        $this->assertSame([StatutCompte::Retire, (int) $administrateur->id], [$responsable->refresh()->statut, (int) $responsable->statut_change_par]);
        $this->assertSessionsFermees($responsable);
    }

    public function test_seul_le_proprietaire_retrograde_un_responsable_en_vendeur(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $autreResponsable = $this->responsable($magasin);
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes());

        foreach ([$autreResponsable, $administrateur] as $auteur) {
            $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($auteur, $responsable, [$magasin->id => ['role' => RoleMagasin::Vendeur]]), AuthorizationException::class);
        }
        app(ModifierMembreAction::class)->handle($this->proprietaireDe($magasin), $responsable, [$magasin->id => ['role' => RoleMagasin::Vendeur]]);

        $affectation = Affectation::query()->where('user_id', $responsable->id)->sole();
        $this->assertSame(RoleMagasin::Vendeur, $affectation->role);
        $this->assertEqualsCanonicalizing(RoleMagasin::Vendeur->permissionsParDefaut(), $affectation->autorisations());
    }

    public function test_promouvoir_un_vendeur_responsable_efface_ses_cases_dans_ce_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $vendeur = $this->membreDeCesMagasins([$magasin->id => ['role' => RoleMagasin::Vendeur, 'autorisations' => [PermissionRegistry::FACTURE_VIEW]], $autre->id => ['role' => RoleMagasin::Vendeur, 'autorisations' => [PermissionRegistry::PRODUIT_VIEW]]], $magasin);

        app(ModifierMembreAction::class)->handle($this->proprietaireDe($magasin), $vendeur, [$magasin->id => ['role' => RoleMagasin::Responsable]]);

        $this->assertSame([], Affectation::query()->where('user_id', $vendeur->id)->where('magasin_id', $magasin->id)->sole()->autorisations());
        $this->assertSame([PermissionRegistry::PRODUIT_VIEW], Affectation::query()->where('user_id', $vendeur->id)->where('magasin_id', $autre->id)->sole()->autorisations());
    }

    public function test_nommer_un_responsable_s_inscrit_au_jet_130_et_au_journal_d_audit(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW], ['name' => 'Paul']);

        app(ModifierMembreAction::class)->handle($administrateur, $vendeur, [$magasin->id => ['role' => RoleMagasin::Responsable]]);

        $dernier = collect($this->modificationsDesDroits($magasin))->last();
        $this->assertSame(['id' => $vendeur->id, 'nom' => 'Paul'], $dernier['membre']);
        $this->assertSame(['vendeur', 'responsable'], [$dernier['avant']['role_magasin'], $dernier['apres']['role_magasin']]);
        $this->assertEqualsCanonicalizing(PermissionRegistry::toutes(), $dernier['apres']['permissions']);
        $this->assertCount(1, $this->entreesDAudit($magasin->societe, ActionAuditee::ResponsableNomme));
    }

    /**
     * Dans une société suspendue, l'équipe reste gérable : l'autorisation
     * de nommer les responsables y vaut encore ; dans une société
     * révoquée, plus rien.
     */
    public function test_l_autorisation_de_nommer_reste_permise_dans_une_societe_suspendue_mais_pas_revoquee(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::RESPONSABLES_NOMMER, AutorisationsSociete::MAGASINS_GERER]);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $responsable = $this->responsable($magasin);
        $magasin->societe->forceFill(['statut' => StatutSociete::Suspendue])->save();

        $this->assertTrue($administrateur->can(AutorisationsSociete::RESPONSABLES_NOMMER));
        $this->assertFalse($administrateur->can(AutorisationsSociete::MAGASINS_GERER));
        app(ModifierMembreAction::class)->handle($administrateur, $vendeur, [$magasin->id => ['role' => RoleMagasin::Responsable]]);
        $this->assertSame(RoleMagasin::Responsable, Affectation::query()->where('user_id', $vendeur->id)->sole()->role);

        $magasin->societe->forceFill(['statut' => StatutSociete::Revoquee])->save();
        $this->assertFalse($administrateur->can(AutorisationsSociete::RESPONSABLES_NOMMER));
        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        $this->assertFalse($administrateur->can('retirerDeLaSociete', $responsable));
    }

    /**
     * Une vente émise par ce compte, dans son magasin.
     */
    private function venteDe(User $vendeur): Facture
    {
        $magasin = Magasin::query()->findOrFail(Affectation::query()->where('user_id', $vendeur->id)->value('magasin_id'));
        $this->dansLeMagasin($magasin, $vendeur);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 12, 'taux_tva' => 20, 'suivi_stock' => false]);

        return app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]));
    }
}
