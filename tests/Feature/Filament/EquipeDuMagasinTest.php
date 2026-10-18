<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CreerExpertComptableAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\EnvoyerUnLienDeReinitialisationAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Filament\App\Resources\EquipeDuMagasinResource;
use App\Filament\App\Resources\EquipeDuMagasinResource\Pages\CreateMembreDuMagasin;
use App\Filament\App\Resources\EquipeDuMagasinResource\Pages\ListEquipeDuMagasin;
use App\Filament\Societe\Resources\MembreResource\Pages\FicheDuMembre;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * L'équipe d'un magasin, dans son panneau (voir EquipeDuMagasinResource) :
 * son propriétaire et ses responsables y créent les vendeurs et les
 * observateurs du magasin et cochent leurs cases ; un responsable ne gère
 * que les vendeurs et les observateurs dont tous les magasins sont les
 * siens, voit un membre qui travaille aussi ailleurs sans savoir où, et ne
 * touche jamais un autre responsable, un administrateur, l'expert-comptable
 * ni le propriétaire. Une même personne n'est affectée à plusieurs
 * magasins que par le propriétaire.
 */
class EquipeDuMagasinTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_responsable_cree_un_vendeur_et_un_observateur_de_son_magasin_avec_leurs_autorisations(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $this->dansLeMagasin($magasin, $responsable);

        foreach ([['paul@example.test', RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::PARAMETRES_GERER]], ['lea@example.test', RoleMagasin::Observateur, [PermissionRegistry::PRODUIT_VIEW]]] as [$email, $role, $autorisations]) {
            Livewire::test(CreateMembreDuMagasin::class)
                ->fillForm(['name' => $email, 'email' => $email, 'mot_de_passe_provisoire' => self::MOT_DE_PASSE_PROVISOIRE, 'role' => $role->value])
                ->set('data.autorisations', $autorisations)
                ->set('data.autorisations_vente', [])
                ->call('create')
                ->assertHasNoFormErrors();

            $compte = User::query()->where('email', $email)->sole();
            $affectation = Affectation::query()->where('user_id', $compte->id)->sole();
            $this->assertSame([(int) $magasin->id, $role, (int) $responsable->id], [(int) $affectation->magasin_id, $affectation->role, (int) $compte->cree_par]);
            $this->assertEqualsCanonicalizing($autorisations, $affectation->autorisations());
        }
    }

    /**
     * Un administrateur, même de tous les magasins et avec toutes les
     * cases et toutes les autorisations de société, ne crée jamais un
     * vendeur ou un observateur : ni par l'écran, ni par une requête
     * forgée, ni par l'action.
     */
    public function test_un_administrateur_ne_cree_jamais_un_vendeur_ou_un_observateur(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes(), PermissionRegistry::toutes());

        $this->dansLeMagasin($magasin, $administrateur);
        $this->get(EquipeDuMagasinResource::getUrl('create', ['tenant' => $magasin]))->assertForbidden();
        Livewire::test(CreateMembreDuMagasin::class)->assertForbidden();
        foreach ([RoleMagasin::Vendeur, RoleMagasin::Observateur] as $role) {
            $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($administrateur, $magasin->societe, ['name' => 'Paul', 'email' => 'paul@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => $role]]), AuthorizationException::class);
        }

        $this->assertFalse(User::query()->where('email', 'paul@example.test')->exists());
    }

    /**
     * Un responsable ne crée ni ne nomme un responsable, et ne touche
     * aucune affectation de responsable ni aucun compte de responsable,
     * d'administrateur ou du propriétaire : ni par l'écran, ni par une
     * requête forgée, ni par l'action.
     */
    public function test_un_responsable_ne_cree_ni_ne_touche_un_autre_responsable_ni_un_administrateur_ni_le_proprietaire(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);
        $coResponsable = $this->responsable($magasin);
        $administrateur = $this->administrateur($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $affectationDuCoResponsable = Affectation::query()->where('user_id', $coResponsable->id)->sole();

        $this->dansLeMagasin($magasin, $responsable);
        Livewire::test(CreateMembreDuMagasin::class)
            ->assertFormFieldExists('role', fn ($champ): bool => ! array_key_exists(RoleMagasin::Responsable->value, $champ->getOptions()))
            ->fillForm(['name' => 'Chef', 'email' => 'chef@example.test', 'mot_de_passe_provisoire' => self::MOT_DE_PASSE_PROVISOIRE, 'role' => RoleMagasin::Responsable->value])
            ->call('create');
        $liste = Livewire::test(ListEquipeDuMagasin::class);
        foreach (['edit', 'retirer_du_magasin', 'suspendre', 'retirer_de_la_societe', 'renvoyer_un_lien'] as $geste) {
            $liste->assertTableActionHidden($geste, $affectationDuCoResponsable);
        }
        $this->forgerLActionDeLigne($liste, 'retirer_du_magasin', $affectationDuCoResponsable);
        $this->forgerLActionDeLigne($liste, 'suspendre', $affectationDuCoResponsable);
        $this->forgerLActionDeLigne($liste, 'nommer_responsable', Affectation::query()->where('user_id', $vendeur->id)->sole());
        $this->get(EquipeDuMagasinResource::getUrl('edit', ['record' => $affectationDuCoResponsable, 'tenant' => $magasin]))->assertForbidden();

        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($responsable, $vendeur, [$magasin->id => ['role' => RoleMagasin::Responsable]]), AuthorizationException::class);
        foreach ([$coResponsable, $administrateur, $proprietaire] as $cible) {
            $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->suspendre($responsable, $cible), AuthorizationException::class);
            $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->retirer($responsable, $cible), AuthorizationException::class);
            $this->assertThrows(fn () => app(EnvoyerUnLienDeReinitialisationAction::class)->handle($responsable, $cible), AuthorizationException::class);
            $this->assertSame(StatutCompte::Actif, $cible->refresh()->statut, $cible->email);
        }
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($responsable, $coResponsable, [$magasin->id => null]), AuthorizationException::class);

        $this->assertFalse(User::query()->where('email', 'chef@example.test')->exists());
        $this->assertSame(RoleMagasin::Responsable, $affectationDuCoResponsable->fresh()->role);
        $this->assertSame(RoleMagasin::Vendeur, Affectation::query()->where('user_id', $vendeur->id)->sole()->role);
    }

    public function test_un_responsable_ne_cree_ni_ne_touche_l_expert_comptable(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $expert = $this->expertComptable($magasin);

        $this->dansLeMagasin($magasin, $responsable);
        Livewire::test(ListEquipeDuMagasin::class)->assertCanNotSeeTableRecords(Affectation::query()->where('user_id', $expert->id)->get());
        $this->assertFalse($responsable->can('creerLExpertComptable', User::class));
        $this->assertThrows(fn () => app(CreerExpertComptableAction::class)->handle($responsable, $magasin->societe, ['name' => 'Cabinet', 'email' => 'cabinet@example.test'], self::MOT_DE_PASSE_PROVISOIRE), AuthorizationException::class);
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->suspendre($responsable, $expert), AuthorizationException::class);
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->retirer($responsable, $expert), AuthorizationException::class);
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($responsable, $expert, [$magasin->id => ['role' => RoleMagasin::Vendeur]]), AuthorizationException::class);

        $this->assertSame([StatutCompte::Actif, true], [$expert->refresh()->statut, $expert->estExpertComptable()]);
        $this->assertFalse(User::query()->where('email', 'cabinet@example.test')->exists());
    }

    /**
     * Le responsable du magasin A ne gère rien dans le magasin B : la fiche
     * d'une affectation de B répond 404, un identifiant forgé dans sa liste
     * ne fait rien, et l'action refuse.
     */
    public function test_un_responsable_du_magasin_a_ne_gere_rien_dans_le_magasin_b(): void
    {
        $magasinA = $this->ouvrirUneSociete(['nom' => 'A']);
        $magasinB = $this->ouvrirUnAutreMagasin($magasinA, ['nom' => 'B']);
        $responsable = $this->responsable($magasinA);
        $vendeurDeB = $this->membre($magasinB, RoleMagasin::Vendeur);
        $affectationDeB = Affectation::query()->where('user_id', $vendeurDeB->id)->sole();

        $this->dansLeMagasin($magasinA, $responsable);
        $this->get(EquipeDuMagasinResource::getUrl('edit', ['record' => $affectationDeB, 'tenant' => $magasinA]))->assertNotFound();
        $this->get(EquipeDuMagasinResource::getUrl('edit', ['record' => $affectationDeB, 'tenant' => $magasinB]))->assertNotFound();
        $liste = Livewire::test(ListEquipeDuMagasin::class);
        $this->forgerLActionDeLigne($liste, 'retirer_du_magasin', $affectationDeB);
        $this->forgerLActionDeLigne($liste, 'suspendre', $affectationDeB);
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($responsable, $vendeurDeB, [$magasinB->id => null]), AuthorizationException::class);
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->suspendre($responsable, $vendeurDeB), AuthorizationException::class);

        $this->assertSame([StatutCompte::Actif, RoleMagasin::Vendeur], [$vendeurDeB->refresh()->statut, $affectationDeB->fresh()->role]);
    }

    public function test_deux_responsables_du_meme_magasin_ne_se_touchent_pas(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $premier = $this->responsable($magasin);
        $second = $this->responsable($magasin);

        foreach ([[$premier, $second], [$second, $premier]] as [$auteur, $cible]) {
            $this->assertFalse(app(PorteeDesDroits::class)->gereLeCompte($auteur, $cible));
            $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($auteur, $cible, [$magasin->id => null]), AuthorizationException::class);
            $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->suspendre($auteur, $cible), AuthorizationException::class);
            $this->assertSame(RoleMagasin::Responsable, Affectation::query()->where('user_id', $cible->id)->sole()->role);
        }
    }

    /**
     * Un membre que le propriétaire a affecté à plusieurs magasins ne se
     * gère que par le propriétaire : le responsable d'un seul de ses
     * magasins le voit dans son équipe, mais ne coche ni ne retire rien,
     * ne le suspend pas, ne renouvelle pas son accès, et ne voit pas ses
     * autres magasins.
     */
    public function test_le_responsable_d_un_seul_des_magasins_d_un_vendeur_ne_le_gere_pas(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membreDeCesMagasins([$magasin->id => ['role' => RoleMagasin::Vendeur, 'autorisations' => [PermissionRegistry::FACTURE_VIEW]], $autre->id => ['role' => RoleMagasin::Vendeur]], $magasin);
        $saLigneIci = Affectation::query()->where('user_id', $vendeur->id)->where('magasin_id', $magasin->id)->sole();

        $this->dansLeMagasin($magasin, $responsable);
        $liste = Livewire::test(ListEquipeDuMagasin::class)
            ->assertCanSeeTableRecords([$saLigneIci])
            ->assertSee('Aussi dans d\'autres magasins')
            ->assertDontSee('Mon Autre Boutique');
        foreach (['edit', 'retirer_du_magasin', 'suspendre', 'retirer_de_la_societe', 'renvoyer_un_lien'] as $geste) {
            $liste->assertTableActionHidden($geste, $saLigneIci);
        }
        $this->get(EquipeDuMagasinResource::getUrl('edit', ['record' => $saLigneIci, 'tenant' => $magasin]))->assertForbidden();
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($responsable, $vendeur, [$magasin->id => ['role' => RoleMagasin::Vendeur, 'autorisations' => [PermissionRegistry::AVOIR_CREER]]]), AuthorizationException::class);
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($responsable, $vendeur, [$magasin->id => null]), AuthorizationException::class);
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->suspendre($responsable, $vendeur), AuthorizationException::class);
        $this->assertThrows(fn () => app(EnvoyerUnLienDeReinitialisationAction::class)->handle($responsable, $vendeur), AuthorizationException::class);

        $this->assertSame([PermissionRegistry::FACTURE_VIEW], $saLigneIci->autorisations());
        $this->assertSame(2, Affectation::query()->where('user_id', $vendeur->id)->count());
        $this->assertSame(StatutCompte::Actif, $vendeur->refresh()->statut);
    }

    /**
     * Le responsable de tous les magasins d'un vendeur gère tout son
     * compte : suspendre, réactiver, renouveler son accès, le retirer.
     */
    public function test_le_responsable_de_tous_les_magasins_d_un_vendeur_suspend_reactive_retire_et_renouvelle_son_acces(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $responsable = $this->responsable($magasin, $autre);
        $vendeur = $this->membreDeCesMagasins([$magasin->id => ['role' => RoleMagasin::Vendeur], $autre->id => ['role' => RoleMagasin::Observateur]], $magasin);
        $saLigne = Affectation::query()->where('user_id', $vendeur->id)->where('magasin_id', $magasin->id)->sole();

        $this->dansLeMagasin($magasin, $responsable);
        Livewire::test(ListEquipeDuMagasin::class)->callTableAction('suspendre', $saLigne->fresh());
        $this->assertTrue($vendeur->refresh()->estSuspendu());
        Livewire::test(ListEquipeDuMagasin::class)->callTableAction('reactiver', $saLigne->fresh());
        $this->assertTrue($vendeur->refresh()->estActif());
        Livewire::test(ListEquipeDuMagasin::class)->callTableAction('renvoyer_un_lien', $saLigne->fresh())->assertNotified('Le lien est parti.');
        Livewire::test(ListEquipeDuMagasin::class)->callTableAction('retirer_de_la_societe', $saLigne->fresh());

        $this->assertSame(StatutCompte::Retire, $vendeur->refresh()->statut);
        $this->assertSame(0, Affectation::query()->where('user_id', $vendeur->id)->count());
    }

    /**
     * Un compte responsable dans un autre magasin et vendeur dans celui-ci
     * travaille dans deux magasins : seul le propriétaire le gère, le
     * responsable de ce magasin ne touche pas à son affectation de vendeur.
     */
    public function test_un_responsable_ne_gere_pas_un_compte_responsable_ailleurs(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $responsable = $this->responsable($magasin);
        $compte = $this->membreDeCesMagasins([$magasin->id => ['role' => RoleMagasin::Vendeur], $autre->id => ['role' => RoleMagasin::Responsable]], $magasin);

        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($responsable, $compte, [$magasin->id => ['role' => RoleMagasin::Observateur]]), AuthorizationException::class);
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($responsable, $compte, [$magasin->id => null]), AuthorizationException::class);
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->suspendre($responsable, $compte), AuthorizationException::class);

        app(ModifierMembreAction::class)->handle($this->proprietaireDe($magasin), $compte, [$magasin->id => ['role' => RoleMagasin::Observateur]]);
        $this->assertSame(RoleMagasin::Observateur, Affectation::query()->where('user_id', $compte->id)->where('magasin_id', $magasin->id)->sole()->role);
        $this->assertSame(RoleMagasin::Responsable, Affectation::query()->where('user_id', $compte->id)->where('magasin_id', $autre->id)->sole()->role);
    }

    /**
     * Un responsable ne change ni son affectation, ni son statut : sa
     * ligne n'a aucun bouton, sa fiche ne s'ouvre pas, et l'action refuse.
     */
    public function test_un_responsable_ne_modifie_ni_son_affectation_ni_son_statut(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $saLigne = Affectation::query()->where('user_id', $responsable->id)->sole();

        $this->dansLeMagasin($magasin, $responsable);
        $liste = Livewire::test(ListEquipeDuMagasin::class)->assertCanSeeTableRecords([$saLigne]);
        foreach (['edit', 'retirer_du_magasin', 'suspendre', 'retirer_de_la_societe', 'renvoyer_un_lien', 'redonner_un_provisoire'] as $geste) {
            $liste->assertTableActionHidden($geste, $saLigne);
        }
        $this->get(EquipeDuMagasinResource::getUrl('edit', ['record' => $saLigne, 'tenant' => $magasin]))->assertForbidden();
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($responsable, $responsable, [$magasin->id => null]), AuthorizationException::class);
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->suspendre($responsable, $responsable), AuthorizationException::class);

        $this->assertSame([StatutCompte::Actif, RoleMagasin::Responsable], [$responsable->refresh()->statut, $saLigne->fresh()->role]);
    }

    /**
     * Un vendeur qui a toutes les cases ne gère aucun compte : l'équipe ne
     * s'ouvre pas pour lui, et les actions refusent.
     */
    public function test_un_vendeur_qui_a_toutes_les_cases_ne_gere_aucun_compte(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, PermissionRegistry::toutes());
        $collegue = $this->membre($magasin, RoleMagasin::Vendeur);

        $this->dansLeMagasin($magasin, $vendeur);
        $this->get(EquipeDuMagasinResource::getUrl('index', ['tenant' => $magasin]))->assertForbidden();
        Livewire::test(ListEquipeDuMagasin::class)->assertForbidden();
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->suspendre($vendeur, $collegue), AuthorizationException::class);
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($vendeur, $collegue, [$magasin->id => null]), AuthorizationException::class);
        $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($vendeur, $magasin->societe, ['name' => 'Paul', 'email' => 'paul@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Observateur]]), AuthorizationException::class);

        $this->assertSame(StatutCompte::Actif, $collegue->refresh()->statut);
        $this->assertFalse(User::query()->where('email', 'paul@example.test')->exists());
    }

    /**
     * Retirer un vendeur de son dernier magasin le retire de la société :
     * la confirmation le dit avant.
     */
    public function test_retirer_un_vendeur_de_son_dernier_magasin_le_retire_de_la_societe_apres_confirmation(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul', 'email' => 'paul@example.test']);
        $saLigne = Affectation::query()->where('user_id', $vendeur->id)->sole();

        $this->dansLeMagasin($magasin, $responsable);
        Livewire::test(ListEquipeDuMagasin::class)
            ->mountTableAction('retirer_du_magasin', $saLigne)
            ->assertSee('C\'est le dernier magasin de Paul : Paul sera retiré de la société.')
            ->callMountedTableAction();

        $this->assertSame([StatutCompte::Retire, (int) $responsable->id], [$vendeur->refresh()->statut, (int) $vendeur->statut_change_par]);
        $this->assertModelMissing($saLigne);
    }

    /**
     * Seul le propriétaire affecte une même personne à plusieurs magasins :
     * un responsable n'ajoute pas à son magasin un compte d'un autre
     * magasin, ni par son adresse (la réponse reste neutre), ni par
     * l'action.
     */
    public function test_un_responsable_n_ajoute_pas_a_son_magasin_un_compte_d_un_autre_magasin(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $responsable = $this->responsable($magasin, $autre);
        $vendeurDeLAutre = $this->membre($autre, RoleMagasin::Vendeur, null, ['name' => 'Bruno', 'email' => 'bruno@example.test']);
        $vendeurAilleurs = $this->membre($this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Troisième']), RoleMagasin::Vendeur, null, ['name' => 'Carla', 'email' => 'carla@example.test']);

        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($responsable, $vendeurDeLAutre, [$magasin->id => ['role' => RoleMagasin::Vendeur]]), AuthorizationException::class, PorteeDesDroits::REFUS_PLUSIEURS_MAGASINS);
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($responsable, $vendeurAilleurs, [$magasin->id => ['role' => RoleMagasin::Vendeur]]), AuthorizationException::class);
        try {
            app(CreerMembreAction::class)->handle($responsable, $magasin->societe, ['name' => 'Carla', 'email' => 'carla@example.test'], self::MOT_DE_PASSE_PROVISOIRE, [$magasin->id => ['role' => RoleMagasin::Vendeur]]);
            $this->fail('L\'adresse d\'un compte existant ne sert pas à un nouveau compte.');
        } catch (ValidationException $refus) {
            $this->assertSame(['email' => [CreationDesComptes::MESSAGE_ADRESSE_INUTILISABLE]], $refus->errors());
        }

        $this->assertSame([$autre->id], Affectation::query()->where('user_id', $vendeurDeLAutre->id)->pluck('magasin_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(1, Affectation::query()->where('user_id', $vendeurAilleurs->id)->count());
    }

    /**
     * Le propriétaire affecte une même personne à deux magasins, depuis
     * l'administration de la société, et coche ses cases dans chacun.
     */
    public function test_le_proprietaire_affecte_une_personne_a_deux_magasins_et_coche_ses_cases_dans_chacun(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW]);

        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        Livewire::test(FicheDuMembre::class, ['record' => $vendeur->getRouteKey()])
            ->mountAction('modifierLesMagasins')
            ->set('mountedActionsData.0.affectations', [
                ['magasin_id' => $magasin->id, 'role' => RoleMagasin::Vendeur->value, 'autorisations' => [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::AVOIR_CREER]],
                ['magasin_id' => $autre->id, 'role' => RoleMagasin::Observateur->value, 'autorisations' => [PermissionRegistry::PRODUIT_VIEW]],
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $vendeur->refresh();
        $this->assertSame([PermissionRegistry::AVOIR_CREER, PermissionRegistry::FACTURE_VIEW], $vendeur->autorisationsDans($magasin));
        $this->assertSame([PermissionRegistry::PRODUIT_VIEW], $vendeur->autorisationsDans($autre));
        $this->assertSame(RoleMagasin::Observateur, Affectation::query()->where('user_id', $vendeur->id)->where('magasin_id', $autre->id)->sole()->role);
    }

    /**
     * L'équipe d'un magasin dit qu'un membre travaille aussi ailleurs,
     * jamais où.
     */
    public function test_l_equipe_d_un_magasin_ne_dit_jamais_dans_quels_autres_magasins_travaille_un_membre(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $secret = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Boutique Discrète']);
        $vendeur = $this->membreDeCesMagasins([$magasin->id => ['role' => RoleMagasin::Vendeur], $secret->id => ['role' => RoleMagasin::Observateur]], $magasin, ['name' => 'Paul', 'email' => 'paul@example.test']);

        foreach ([$this->proprietaireDe($magasin), $this->responsable($magasin)] as $compte) {
            $this->dansLeMagasin($magasin, $compte);
            Livewire::test(ListEquipeDuMagasin::class)
                ->assertCanSeeTableRecords(Affectation::query()->where('user_id', $vendeur->id)->where('magasin_id', $magasin->id)->get())
                ->assertSee('Paul')
                ->assertSee('Aussi dans d\'autres magasins')
                ->assertDontSee('Boutique Discrète');
        }
        $this->assertSame(2, Affectation::query()->where('user_id', $vendeur->id)->count());
    }

    /**
     * Chaque situation de lecture seule où l'équipe se gère encore, et la
     * société révoquée, où plus rien ne se gère.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function situationsDeLectureSeule(): array
    {
        return [
            'magasin suspendu' => ['magasin suspendu', true],
            'magasin fermé' => ['magasin fermé', true],
            'société suspendue' => ['société suspendue', true],
            'société résiliée' => ['société résiliée', true],
            'inscription refusée' => ['inscription refusée', true],
            'société révoquée' => ['société révoquée', false],
        ];
    }

    /**
     * Dans un magasin fermé ou suspendu, ou une société suspendue,
     * résiliée ou à l'inscription refusée, l'équipe reste consultable et
     * gérable, au magasin par le propriétaire et ses responsables, et dans
     * l'administration de la société par le propriétaire : un accès se
     * coupe toujours. Dans une société révoquée, elle reste consultable,
     * mais plus rien ne s'y gère.
     */
    #[DataProvider('situationsDeLectureSeule')]
    public function test_l_equipe_reste_geree_dans_un_magasin_ferme_ou_une_societe_suspendue_mais_pas_dans_une_societe_revoquee(string $situation, bool $seGere): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);
        $parLeProprietaire = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul', 'email' => 'paul@example.test']);
        $parLeResponsable = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Jean', 'email' => 'jean@example.test']);
        $dansLaSociete = $this->membre($magasin, RoleMagasin::Observateur, null, ['name' => 'Léa', 'email' => 'lea@example.test']);
        $this->passerEn($magasin, $situation);

        foreach ([[$proprietaire, $parLeProprietaire], [$responsable, $parLeResponsable]] as [$gestionnaire, $vendeur]) {
            $saLigne = Affectation::query()->where('user_id', $vendeur->id)->sole();
            $this->flushSession();
            $this->actingAs($gestionnaire)->get(EquipeDuMagasinResource::getUrl('index', ['tenant' => $magasin]))->assertOk()->assertSee($vendeur->email);
            $this->get(EquipeDuMagasinResource::getUrl('create', ['tenant' => $magasin]))->assertStatus($seGere ? 200 : 403);
            $this->dansLeMagasin($magasin, $gestionnaire);
            $liste = Livewire::test(ListEquipeDuMagasin::class);

            if ($seGere) {
                $liste->callTableAction('retirer_du_magasin', $saLigne);
                $this->assertModelMissing($saLigne);
                $this->assertFalse($vendeur->refresh()->peutOuvrir($magasin));
            } else {
                $liste->assertTableActionHidden('retirer_du_magasin', $saLigne);
                $this->forgerLActionDeLigne($liste, 'retirer_du_magasin', $saLigne);
                $this->assertModelExists($saLigne);
            }
        }

        $this->dansLEspaceDeLaSociete($magasin->societe, $proprietaire);
        $fiche = Livewire::test(FicheDuMembre::class, ['record' => $dansLaSociete->getRouteKey()]);
        if ($seGere) {
            $fiche->callAction('suspendre');
            $this->assertTrue($dansLaSociete->refresh()->estSuspendu());
        } else {
            $fiche->assertActionHidden('suspendre');
            $this->forgerLAction($fiche, 'suspendre');
            $this->assertTrue($dansLaSociete->refresh()->estActif());
        }
    }

    /**
     * L'écran de l'équipe ne liste que les affectations de membre du
     * magasin actif : ni le propriétaire, ni les administrateurs, ni
     * l'expert-comptable, ni les membres d'un autre magasin.
     */
    public function test_l_ecran_de_l_equipe_ne_liste_que_les_affectations_de_membre_du_magasin_actif(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], User::factory()->create(['email' => 'proprietaire@ma-boutique.test']));
        $autre = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Mon Autre Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $responsable = $this->responsable($magasin);
        $administrateurDeLaSelection = $this->administrateur($magasin, [$magasin->id]);
        $this->administrateur($magasin);
        $this->expertComptable($magasin);
        $vendeurDeLAutre = $this->membre($autre, RoleMagasin::Vendeur);

        $this->dansLeMagasin($magasin, $proprietaire);
        Livewire::test(ListEquipeDuMagasin::class)
            ->assertCanSeeTableRecords(Affectation::query()->whereIn('user_id', [$vendeur->id, $responsable->id])->get())
            ->assertCanNotSeeTableRecords(Affectation::query()->whereIn('user_id', [$vendeurDeLAutre->id, $administrateurDeLaSelection->id])->orWhereNull('magasin_id')->get())
            ->assertCountTableRecords(2)
            ->assertDontSee($proprietaire->email);
    }

    /**
     * Met le magasin dans la situation de lecture seule, par son statut,
     * sa suspension ou sa société.
     */
    private function passerEn(Magasin $magasin, string $situation): void
    {
        match ($situation) {
            'magasin suspendu' => $magasin->forceFill(['suspendu_le' => now()])->save(),
            'magasin fermé' => $magasin->forceFill(['statut' => StatutMagasin::Ferme])->save(),
            'société suspendue' => $magasin->societe->forceFill(['statut' => StatutSociete::Suspendue])->save(),
            'société résiliée' => $magasin->societe->forceFill(['statut' => StatutSociete::Resiliee])->save(),
            'société révoquée' => $magasin->societe->forceFill(['statut' => StatutSociete::Revoquee])->save(),
            'inscription refusée' => $magasin->societe->forceFill(['validee_le' => null, 'refusee_le' => now()])->save(),
        };
    }
}
