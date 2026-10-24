<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Notifications\FacturesPasseesEnRetard;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Contracts\SourceDeHasard;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Identifiant;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\EquipeDuMagasinResource\Pages\EditMembreDuMagasin;
use App\Filament\App\Resources\EquipeDuMagasinResource\Pages\ListEquipeDuMagasin;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Les cases d'un vendeur ou d'un observateur, dans le magasin de son
 * compte, le seul qu'il ouvre, et les gestes sur son compte : le
 * propriétaire pour tous ; un responsable pour les vendeurs et les
 * observateurs de son magasin, jamais au-delà de ce qu'il a dans son
 * magasin ; un administrateur pour aucun. Chaque modification s'inscrit,
 * relue en base, au journal des événements du magasin et au journal
 * d'audit.
 */
class AutorisationsDesMembresTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Une personne qui vend dans deux magasins y a un compte dans chacun :
     * le propriétaire coche les cases de chaque compte, qui ne valent que
     * dans son magasin.
     */
    public function test_le_proprietaire_coche_les_autorisations_de_chacun_des_deux_comptes_d_une_personne(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $compteIci = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul']);
        $compteLaBas = $this->membre($autre, RoleMagasin::Vendeur, null, ['name' => 'Paul']);

        app(ModifierMembreAction::class)->handle($proprietaire, $compteIci, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::AVOIR_CREER]);
        app(ModifierMembreAction::class)->handle($proprietaire, $compteLaBas, RoleMagasin::Vendeur, [PermissionRegistry::PRODUIT_VIEW]);

        $this->assertSame([PermissionRegistry::AVOIR_CREER, PermissionRegistry::FACTURE_VIEW], $compteIci->refresh()->autorisationsDans($magasin));
        $this->assertSame([PermissionRegistry::PRODUIT_VIEW], $compteLaBas->refresh()->autorisationsDans($autre));
        $this->assertSame([[], []], [$compteIci->autorisationsDans($autre), $compteLaBas->autorisationsDans($magasin)]);
        app(TenantContext::class)->set($autre);
        $this->assertFalse($compteIci->can(PermissionRegistry::AVOIR_CREER));
        $this->assertFalse($compteLaBas->can(PermissionRegistry::AVOIR_CREER));
    }

    /**
     * Un responsable coche ce qu'il veut, sauf rien de plus que tout, pour
     * un vendeur de son magasin ; il ne coche rien dans un autre magasin.
     */
    public function test_un_responsable_coche_les_autorisations_d_un_vendeur_dans_son_seul_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $vendeurDeLAutre = $this->membre($autre, RoleMagasin::Vendeur);

        app(ModifierMembreAction::class)->handle($responsable, $vendeur, RoleMagasin::Vendeur, [PermissionRegistry::PARAMETRES_GERER, PermissionRegistry::FACTURE_VIEW]);
        $this->assertSame([PermissionRegistry::FACTURE_VIEW, PermissionRegistry::PARAMETRES_GERER], $vendeur->refresh()->autorisationsDans($magasin));

        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($responsable, $vendeurDeLAutre, RoleMagasin::Vendeur, PermissionRegistry::toutes()), AuthorizationException::class);
        $this->assertEqualsCanonicalizing(RoleMagasin::Vendeur->permissionsParDefaut(), $vendeurDeLAutre->refresh()->autorisationsDans($autre));
        $this->assertSame([$magasin->id], Affectation::query()->where('user_id', $vendeur->id)->pluck('magasin_id')->all());
    }

    /**
     * Un administrateur, même de tous les magasins et autorisé à tout, ne
     * coche jamais les cases d'un vendeur ou d'un observateur, ni à la
     * création, ni ensuite.
     */
    public function test_un_administrateur_ne_coche_jamais_les_autorisations_d_un_vendeur_ou_d_un_observateur(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes(), PermissionRegistry::toutes());
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);
        $observateur = $this->membre($magasin, RoleMagasin::Observateur, [PermissionRegistry::FACTURE_VIEW]);

        foreach ([$vendeur, $observateur] as $compte) {
            $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($administrateur, $compte, RoleMagasin::Vendeur, [PermissionRegistry::AVOIR_CREER]), AuthorizationException::class);
            $this->assertSame([PermissionRegistry::FACTURE_VIEW], $compte->refresh()->autorisationsDans($magasin));
        }
        $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($administrateur, $magasin, ['name' => 'Paul'], RoleMagasin::Vendeur), AuthorizationException::class);
        $this->assertFalse(User::query()->where('name', 'Paul')->exists());
        $this->assertSame([], app(PorteeDesDroits::class)->autorisationsCochables($administrateur, $magasin));
    }

    /**
     * Un vendeur, même avec toutes les cases, ne coche rien pour personne.
     */
    public function test_un_simple_membre_ne_coche_rien(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeurQuiATout = $this->membre($magasin, RoleMagasin::Vendeur, PermissionRegistry::toutes());
        $collegue = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);

        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($vendeurQuiATout, $collegue, RoleMagasin::Vendeur, [PermissionRegistry::AVOIR_CREER]), AuthorizationException::class);
        $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($vendeurQuiATout, $magasin, ['name' => 'Paul'], RoleMagasin::Vendeur), AuthorizationException::class);

        $this->assertSame([PermissionRegistry::FACTURE_VIEW], $collegue->refresh()->autorisationsDans($magasin));
        $this->assertSame([], app(PorteeDesDroits::class)->autorisationsCochables($vendeurQuiATout, $magasin));
    }

    public function test_une_autorisation_inconnue_est_refusee_sans_rien_changer(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);
        $evenements = count($this->modificationsDesDroits($magasin));

        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($this->proprietaireDe($magasin), $vendeur, RoleMagasin::Observateur, ['facture.tout_faire']), InvalidArgumentException::class);
        $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($this->proprietaireDe($magasin), $magasin, ['name' => 'Paul'], RoleMagasin::Vendeur, ['equipe.creer']), InvalidArgumentException::class);

        $this->assertSame(RoleMagasin::Vendeur, Affectation::query()->where('user_id', $vendeur->id)->sole()->role);
        $this->assertSame([PermissionRegistry::FACTURE_VIEW], $vendeur->refresh()->autorisationsDans($magasin));
        $this->assertCount($evenements, $this->modificationsDesDroits($magasin));
        $this->assertFalse(User::query()->where('name', 'Paul')->exists());
    }

    /**
     * Un administrateur ne touche ni au propriétaire, ni à un autre
     * administrateur, ni à lui-même : ni ses droits, ni son statut.
     */
    public function test_un_administrateur_ne_touche_ni_au_proprietaire_ni_a_un_administrateur_ni_a_lui_meme(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes());
        $autreAdministrateur = $this->administrateur($magasin);

        foreach ([$proprietaire, $autreAdministrateur, $administrateur] as $cible) {
            $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->suspendre($administrateur, $cible), AuthorizationException::class);
            $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->retirer($administrateur, $cible), AuthorizationException::class);
            $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($administrateur, $cible, RoleMagasin::Vendeur), AuthorizationException::class);
            $this->assertSame(StatutCompte::Actif, $cible->refresh()->statut, $cible->name);
        }
        $this->assertSame([RoleSociete::Proprietaire, RoleSociete::Administrateur, RoleSociete::Administrateur], [$proprietaire->refresh()->role_societe, $autreAdministrateur->refresh()->role_societe, $administrateur->refresh()->role_societe]);
    }

    /**
     * Les vendeurs et les observateurs se gèrent par le propriétaire et
     * leurs responsables : un administrateur, même autorisé à tout, ne les
     * modifie, ne les suspend ni ne les retire.
     */
    public function test_un_administrateur_ne_modifie_ne_suspend_ni_ne_retire_aucun_vendeur_ou_observateur(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes(), PermissionRegistry::toutes());
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $observateur = $this->membre($magasin, RoleMagasin::Observateur);

        foreach ([$vendeur, $observateur] as $compte) {
            $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($administrateur, $compte, RoleMagasin::Observateur), AuthorizationException::class);
            $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->suspendre($administrateur, $compte), AuthorizationException::class);
            $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->retirer($administrateur, $compte), AuthorizationException::class);
            $this->assertSame(StatutCompte::Actif, $compte->refresh()->statut);
            $this->assertSame([$magasin->id], Affectation::query()->where('user_id', $compte->id)->pluck('magasin_id')->all());
        }
    }

    /**
     * Un compte de membre n'a qu'un magasin : le retirer de son magasin, par
     * la liste de l'équipe, le retire de la société.
     */
    public function test_retirer_un_compte_de_son_magasin_le_retire_de_la_societe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        $this->dansLeMagasin($magasin, $proprietaire);
        Livewire::test(ListEquipeDuMagasin::class)->callTableAction('retirer_du_magasin', Affectation::query()->where('user_id', $vendeur->id)->sole());

        $vendeur->refresh();
        $this->assertSame([StatutCompte::Retire, $proprietaire->id], [$vendeur->statut, (int) $vendeur->statut_change_par]);
        $this->assertSame(0, Affectation::query()->where('user_id', $vendeur->id)->count());
        $this->assertSame(ActionAuditee::CompteRetire, collect($this->entreesDAudit($magasin->societe, ActionAuditee::CompteRetire))->last()?->action);
    }

    /**
     * Un membre actif ou suspendu a toujours son magasin : un compte se
     * crée pour un magasin, et le retirer de son magasin le retire de la
     * société, même suspendu.
     */
    public function test_aucun_membre_actif_ou_suspendu_n_est_jamais_sans_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $suspendu = $this->membre($magasin, RoleMagasin::Vendeur);
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $suspendu);

        $this->dansLeMagasin($magasin, $proprietaire);
        Livewire::test(ListEquipeDuMagasin::class)->callTableAction('retirer_du_magasin', Affectation::query()->where('user_id', $suspendu->id)->sole());

        $this->assertSame(StatutCompte::Retire, $suspendu->refresh()->statut);
        $sansMagasin = User::query()->where('societe_id', $magasin->societe_id)->where('role_societe', RoleSociete::Membre->value)
            ->whereIn('statut', [StatutCompte::Actif->value, StatutCompte::Suspendu->value])
            ->whereNotIn('id', Affectation::query()->select('user_id'))
            ->count();
        $this->assertSame(0, $sansMagasin);
    }

    public function test_un_responsable_ne_reactive_pas_un_compte_suspendu_par_le_proprietaire(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $suspenduParLeProprietaire = $this->membre($magasin, RoleMagasin::Vendeur);
        $suspenduParLeResponsable = $this->membre($magasin, RoleMagasin::Vendeur);
        app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDe($magasin), $suspenduParLeProprietaire);
        app(ChangerStatutCompteAction::class)->suspendre($responsable, $suspenduParLeResponsable);

        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->reactiver($responsable, $suspenduParLeProprietaire), AuthorizationException::class);
        app(ChangerStatutCompteAction::class)->reactiver($responsable, $suspenduParLeResponsable);

        $this->assertSame([StatutCompte::Suspendu, StatutCompte::Actif], [$suspenduParLeProprietaire->refresh()->statut, $suspenduParLeResponsable->refresh()->statut]);
    }

    /**
     * Personne ne change le nom ni l'adresse d'un autre compte : l'écran de
     * l'équipe ne les propose pas, et les actions de l'équipe ne les
     * reçoivent pas.
     */
    public function test_le_nom_et_l_adresse_d_un_autre_compte_ne_se_modifient_jamais(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul', 'email' => 'paul@example.test']);
        $affectation = Affectation::query()->where('user_id', $vendeur->id)->sole();

        $this->dansLeMagasin($magasin, $proprietaire);
        Livewire::test(EditMembreDuMagasin::class, ['record' => $affectation->getRouteKey()])
            ->assertFormFieldDoesNotExist('name')
            ->assertFormFieldDoesNotExist('email')
            ->set('data.name', 'Pirate')
            ->set('data.email', 'pirate@example.test')
            ->call('save');

        $this->assertSame(['Paul', 'paul@example.test'], [$vendeur->refresh()->name, $vendeur->email]);
    }

    /**
     * Le rôle de société, le statut, les autorisations, la société et le
     * rôle d'une affectation ne se remplissent jamais par masse : seules
     * les actions les écrivent, après leurs contrôles.
     */
    public function test_role_societe_statut_et_autorisations_ne_se_remplissent_jamais_par_masse(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $affectation = Affectation::query()->where('user_id', $vendeur->id)->sole();

        foreach ([
            [$vendeur, ['role_societe' => 'proprietaire', 'statut' => 'retire', 'societe_id' => 999, 'cree_par' => null, 'mot_de_passe_est_l_identifiant' => true, 'email_verified_at' => now(), 'acces_redonne_le' => now()]],
            [$affectation, ['role' => 'responsable', 'magasin_id' => null, 'societe_id' => 999, 'nomme_par' => $vendeur->id]],
        ] as [$modele, $attributs]) {
            try {
                $modele->fill($attributs);
            } catch (MassAssignmentException) {
            }

            $this->assertSame([], array_keys($modele->getDirty()), $modele::class);
        }

        $this->assertSame([], array_intersect(['role_societe', 'statut', 'societe_id', 'cree_par', 'mot_de_passe_est_l_identifiant', 'statut_change_par', 'acces_redonne_le', 'acces_redonne_par'], $vendeur->getFillable()));
    }

    /**
     * Retirer un compte de son magasin inscrit les droits qu'il perd au
     * journal des événements de ce seul magasin : le compte de la même
     * personne dans un autre magasin garde les siens.
     */
    public function test_retirer_d_un_magasin_inscrit_les_droits_perdus_dans_ce_seul_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW], ['name' => 'Paul']);
        $sonCompteLaBas = $this->membre($autre, RoleMagasin::Vendeur, [PermissionRegistry::PRODUIT_VIEW], ['name' => 'Paul']);
        $avantDansLAutre = count($this->modificationsDesDroits($autre));

        app(ChangerStatutCompteAction::class)->retirer($this->proprietaireDe($magasin), $vendeur);

        $this->assertSame([
            'membre' => ['id' => $vendeur->id, 'nom' => 'Paul'],
            'avant' => ['role_societe' => 'membre', 'role_magasin' => 'vendeur', 'statut' => 'actif', 'permissions' => [PermissionRegistry::FACTURE_VIEW]],
            'apres' => null,
        ], collect($this->modificationsDesDroits($magasin))->last());
        $this->assertCount($avantDansLAutre, $this->modificationsDesDroits($autre));
        $this->assertSame([PermissionRegistry::PRODUIT_VIEW], $sonCompteLaBas->refresh()->autorisationsDans($autre));
        $this->assertTrue($sonCompteLaBas->estActif());
    }

    /**
     * Suspendre un compte inscrit le JET 130 au journal des événements de
     * son magasin, et d'aucun autre.
     */
    public function test_suspendre_un_compte_inscrit_le_jet_130_dans_son_seul_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $avantDansLAutre = count($this->modificationsDesDroits($autre));

        app(ChangerStatutCompteAction::class)->suspendre($this->proprietaireDe($magasin), $vendeur);

        $dernier = collect($this->modificationsDesDroits($magasin))->last();
        $this->assertSame([$vendeur->id, 'actif', 'suspendu'], [$dernier['membre']['id'], $dernier['avant']['statut'], $dernier['apres']['statut']]);
        $this->assertCount($avantDansLAutre, $this->modificationsDesDroits($autre));
    }

    /**
     * Retiré de la société, le compte reste (jamais supprimé), mais
     * n'ouvre plus rien, n'a plus aucune affectation ni aucune case, et
     * perd ses notifications. Suspendu, il perd aussi ses notifications ;
     * un simple changement de cases ne les touche pas.
     */
    public function test_retirer_de_la_societe_garde_le_compte_coupe_tout_acces_et_efface_ses_notifications(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $retire = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW_TOUTES]);
        $suspendu = $this->membre($magasin, RoleMagasin::Vendeur);
        Notification::send([$retire, $suspendu], new FacturesPasseesEnRetard($magasin, ['F-A-1']));

        app(ModifierMembreAction::class)->handle($proprietaire, $suspendu, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);
        $this->assertSame(1, $suspendu->notifications()->count());
        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $suspendu);
        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $retire);

        $this->assertNotNull(User::query()->find($retire->id));
        $this->assertFalse($retire->refresh()->peutOuvrir($magasin));
        $this->assertSame([], $retire->getTenants(Filament::getPanel('app'))->modelKeys());
        $this->assertSame(0, Affectation::query()->where('user_id', $retire->id)->count());
        $this->assertSame(0, DB::table('autorisations_affectations')->join('affectations', 'affectations.id', '=', 'autorisations_affectations.affectation_id')->where('affectations.user_id', $retire->id)->count());
        $this->assertSame([0, 0], [$retire->notifications()->count(), $suspendu->notifications()->count()]);
    }

    /**
     * Retiré, le compte garde son identifiant, qui n'est jamais redonné :
     * un nouveau compte d'une autre société qui tire cette valeur en tire
     * une autre.
     */
    public function test_un_compte_retire_ne_libere_jamais_son_identifiant(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $retire = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul']);
        $identifiant = (string) $retire->identifiantDeConnexion();
        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $retire);
        $autreSociete = $this->ouvrirUneSociete(['nom' => 'Autre société']);
        $this->app->instance(SourceDeHasard::class, new class($identifiant) implements SourceDeHasard
        {
            private int $tirages = 0;

            public function __construct(private readonly string $dejaPris) {}

            public function entier(int $min, int $max): int
            {
                return $this->tirages++ === 0 ? (int) $this->dejaPris : 48210937;
            }
        });

        $nouveau = app(TenantContext::class)->runAs($autreSociete, fn (): User => app(CreerMembreAction::class)->handle($this->proprietaireDe($autreSociete), $autreSociete, ['name' => 'Paul'], RoleMagasin::Vendeur));

        $this->assertSame('48210937', $nouveau->identifiantDeConnexion());
        $this->assertSame([$identifiant, (int) $magasin->societe_id], [$retire->refresh()->identifiantDeConnexion(), (int) $retire->societe_id]);
        $this->assertSame(1, Identifiant::withoutGlobalScopes()->where('identifiant', $identifiant)->count());
    }

    /**
     * Le rôle ne fait que précocher les cases de son modèle : un vendeur
     * ou un observateur garde exactement les cases enregistrées, et un
     * changement de rôle sans liste donne le modèle du nouveau rôle.
     */
    public function test_le_role_ne_fait_que_precocher(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::AVOIR_CREER]);
        $observateur = $this->membre($magasin, RoleMagasin::Observateur, [PermissionRegistry::PAIEMENT_CREER]);

        $this->assertSame([PermissionRegistry::AVOIR_CREER], $vendeur->autorisationsDans($magasin));
        $this->assertSame([PermissionRegistry::PAIEMENT_CREER], $observateur->autorisationsDans($magasin));

        app(ModifierMembreAction::class)->handle($proprietaire, $vendeur, RoleMagasin::Observateur);
        $this->assertEqualsCanonicalizing(RoleMagasin::Observateur->permissionsParDefaut(), $vendeur->refresh()->autorisationsDans($magasin));
    }

    /**
     * Une modification s'inscrit au journal des événements du magasin en
     * un seul événement, avec les droits d'avant et d'après relus en base,
     * et au journal d'audit en une entrée.
     */
    public function test_une_modification_s_inscrit_en_un_seul_evenement_avec_les_droits_avant_et_apres_relus_en_base(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW], ['name' => 'Paul']);
        $avant = count($this->modificationsDesDroits($magasin));
        $auditAvant = count($this->entreesDAudit($magasin->societe, ActionAuditee::DroitsModifies));

        app(ModifierMembreAction::class)->handle($this->proprietaireDe($magasin), $vendeur, RoleMagasin::Observateur, [PermissionRegistry::PRODUIT_VIEW, PermissionRegistry::FACTURE_VIEW]);

        $evenements = $this->modificationsDesDroits($magasin);
        $this->assertCount($avant + 1, $evenements);
        $this->assertSame([
            'membre' => ['id' => $vendeur->id, 'nom' => 'Paul'],
            'avant' => ['role_societe' => 'membre', 'role_magasin' => 'vendeur', 'statut' => 'actif', 'permissions' => [PermissionRegistry::FACTURE_VIEW]],
            'apres' => ['role_societe' => 'membre', 'role_magasin' => 'observateur', 'statut' => 'actif', 'permissions' => [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::PRODUIT_VIEW]],
        ], end($evenements));
        $this->assertCount($auditAvant + 1, $this->entreesDAudit($magasin->societe, ActionAuditee::DroitsModifies));
    }

    /**
     * Les actions de l'équipe refusent un auteur d'une autre société, et un
     * compte d'une autre société, sans rien écrire.
     */
    public function test_les_actions_des_membres_refusent_un_auteur_ou_un_compte_d_une_autre_societe(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A']);
        $vendeurDeA = $this->membre($magasinA, RoleMagasin::Vendeur);
        $magasinB = $this->ouvrirUneSociete(['nom' => 'B']);
        $proprietaireB = $this->proprietaireDe($magasinB);

        foreach ([
            'modifier' => fn () => app(ModifierMembreAction::class)->handle($proprietaireB, $vendeurDeA, RoleMagasin::Observateur),
            'suspendre' => fn () => app(ChangerStatutCompteAction::class)->suspendre($proprietaireB, $vendeurDeA),
            'retirer de la société' => fn () => app(ChangerStatutCompteAction::class)->retirer($proprietaireB, $vendeurDeA),
            'créer dans A' => fn () => app(CreerMembreAction::class)->handle($proprietaireB, $magasinA, ['name' => 'Paul'], RoleMagasin::Vendeur),
        ] as $geste => $appel) {
            $this->assertThrows($appel, AuthorizationException::class);
            $this->assertSame([StatutCompte::Actif, RoleMagasin::Vendeur], [$vendeurDeA->refresh()->statut, Affectation::withoutGlobalScopes()->where('user_id', $vendeurDeA->id)->sole()->role], $geste);
        }
        $this->assertFalse(User::query()->where('name', 'Paul')->exists());
    }

    public function test_suspendre_ou_retirer_renouvelle_le_se_souvenir_de_moi(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $suspendu = $this->membre($magasin, RoleMagasin::Vendeur);
        $retire = $this->membre($magasin, RoleMagasin::Vendeur);
        $this->ouvrirUneSessionAilleurs($suspendu);
        $this->ouvrirUneSessionAilleurs($retire);

        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $suspendu);
        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $retire);

        $this->assertSessionsFermees($suspendu);
        $this->assertSessionsFermees($retire);
    }

    /**
     * À la création dans l'équipe du magasin, le rôle coche d'office les
     * autorisations de son modèle ; le propriétaire en décoche une et en
     * coche une autre : le vendeur a exactement ce qui est coché.
     */
    public function test_le_proprietaire_coche_et_decoche_les_autorisations_d_un_nouveau_vendeur(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->dansLeMagasin($magasin, $this->proprietaireDe($magasin));
        $composant = Livewire::test(ListEquipeDuMagasin::class)
            ->mountAction('creerUnCompte')
            ->setActionData(['name' => 'Paul Vendeur', 'role' => RoleMagasin::Vendeur->value]);
        $cochees = [...array_values(array_diff($composant->get('mountedActionsData.0.autorisations'), [PermissionRegistry::FACTURE_ENVOYER])), PermissionRegistry::AVOIR_CREER];

        $composant->set('mountedActionsData.0.autorisations', [])
            ->setActionData(['autorisations' => $cochees])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $cases = Affectation::query()->where('user_id', User::query()->where('name', 'Paul Vendeur')->sole()->id)->sole()->autorisations();
        $this->assertNotContains(PermissionRegistry::FACTURE_ENVOYER, $cases);
        $this->assertContains(PermissionRegistry::AVOIR_CREER, $cases);
        $this->assertContains(PermissionRegistry::FACTURE_CREATE, $cases);
    }

    public function test_changer_de_role_coche_le_modele_du_nouveau_role(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $this->dansLeMagasin($magasin, $this->proprietaireDe($magasin));

        $composant = Livewire::test(EditMembreDuMagasin::class, ['record' => Affectation::query()->where('user_id', $vendeur->id)->sole()->getRouteKey()])
            ->set('data.role', RoleMagasin::Observateur->value);

        $this->assertEqualsCanonicalizing(RoleMagasin::Observateur->permissionsParDefaut(), $composant->get('data.autorisations'));
    }

    /**
     * Le propriétaire peut cocher chaque autorisation pour un membre,
     * paramètres du magasin compris, sous son libellé, celles de la vente,
     * qu'il n'exerce pas lui-même, dans leur groupe à part.
     */
    public function test_le_proprietaire_peut_cocher_chaque_autorisation_sous_son_libelle(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $this->dansLeMagasin($magasin, $this->proprietaireDe($magasin));
        $proposees = [];

        Livewire::test(ListEquipeDuMagasin::class)
            ->mountAction('creerUnCompte')
            ->setActionData(['role' => RoleMagasin::Vendeur->value])
            ->assertFormFieldExists('autorisations', 'mountedActionForm', function (CheckboxList $autorisations) use (&$proposees): bool {
                $proposees = array_merge($proposees, array_keys($autorisations->getOptions()));

                return $autorisations->getOptions()[PermissionRegistry::PARAMETRES_GERER] === PermissionRegistry::libelles()[PermissionRegistry::PARAMETRES_GERER];
            })
            ->assertFormFieldExists('autorisations_vente', 'mountedActionForm', function (CheckboxList $vente) use (&$proposees): bool {
                $proposees = array_merge($proposees, array_keys($vente->getOptions()));

                return collect($vente->getOptions())->keys()->sort()->values()->all() === collect(PermissionRegistry::deVente())->sort()->values()->all()
                    && $vente->getOptions()[PermissionRegistry::FACTURE_EMETTRE] === PermissionRegistry::libelles()[PermissionRegistry::FACTURE_EMETTRE];
            })
            ->assertFormFieldIsEnabled('autorisations', 'mountedActionForm')
            ->assertFormFieldIsEnabled('autorisations_vente', 'mountedActionForm');

        $this->assertCount(count(PermissionRegistry::toutes()), $proposees);
        $this->assertEqualsCanonicalizing(PermissionRegistry::toutes(), $proposees);
    }

    /**
     * Suspendre puis réactiver un compte garde ses cases telles quelles.
     */
    public function test_changer_le_statut_sans_changer_le_role_garde_les_autorisations_cochees(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::AVOIR_CREER, PermissionRegistry::FACTURE_VIEW]);

        app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $vendeur);
        app(ChangerStatutCompteAction::class)->reactiver($proprietaire, $vendeur);

        $this->assertSame([PermissionRegistry::AVOIR_CREER, PermissionRegistry::FACTURE_VIEW], $vendeur->refresh()->autorisationsDans($magasin));
    }

    /**
     * Le propriétaire n'est jamais une cible de l'équipe : ni retiré, ni
     * modifié, même par lui-même.
     */
    public function test_impossible_de_retirer_le_proprietaire(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);

        foreach ([$proprietaire, $responsable] as $auteur) {
            $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->retirer($auteur, $proprietaire), AuthorizationException::class);
            $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($auteur, $proprietaire, RoleMagasin::Vendeur), AuthorizationException::class);
        }
        $this->assertSame([StatutCompte::Actif, RoleSociete::Proprietaire], [$proprietaire->refresh()->statut, $proprietaire->role_societe]);
        $this->assertInstanceOf(Magasin::class, $magasin);
    }
}
