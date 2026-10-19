<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CreerExpertComptableAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Filament\App\Resources\EquipeDuMagasinResource;
use App\Filament\App\Resources\EquipeDuMagasinResource\Pages\ListEquipeDuMagasin;
use App\Filament\Societe\Resources\MembreResource\Pages\FicheDuMembre;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * L'équipe d'un magasin, dans son panneau (voir EquipeDuMagasinResource) :
 * son propriétaire et ses responsables y créent les comptes de vendeur et
 * d'observateur du magasin, qui ne valent que pour lui, et cochent leurs
 * cases ; un responsable gère les vendeurs et les observateurs de son
 * magasin, voit leur identifiant, et ne touche jamais un autre
 * responsable, un administrateur, l'expert-comptable ni le propriétaire,
 * ni un compte d'un autre magasin.
 */
class EquipeDuMagasinTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_responsable_cree_un_vendeur_et_un_observateur_de_son_magasin_avec_leurs_autorisations(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $this->dansLeMagasin($magasin, $responsable);

        foreach ([['Paul', RoleMagasin::Vendeur, [PermissionRegistry::FACTURE_VIEW, PermissionRegistry::PARAMETRES_GERER]], ['Léa', RoleMagasin::Observateur, [PermissionRegistry::PRODUIT_VIEW]]] as [$nom, $role, $autorisations]) {
            Livewire::test(ListEquipeDuMagasin::class)
                ->mountAction('creerUnCompte')
                ->setActionData(['name' => $nom, 'role' => $role->value])
                ->set('mountedActionsData.0.autorisations', [])
                ->setActionData(['autorisations' => $autorisations, 'autorisations_vente' => []])
                ->callMountedAction()
                ->assertHasNoActionErrors()
                ->assertActionMounted('compteCree');

            $compte = User::query()->where('societe_id', $magasin->societe_id)->where('name', $nom)->sole();
            $affectation = Affectation::query()->where('user_id', $compte->id)->sole();
            $this->assertSame([(int) $magasin->id, $role, (int) $responsable->id, null], [(int) $affectation->magasin_id, $affectation->role, (int) $compte->cree_par, $compte->email]);
            $this->assertEqualsCanonicalizing($autorisations, $affectation->autorisations());
            $this->assertSame([true, (int) $magasin->id], [$compte->mot_de_passe_est_l_identifiant, (int) $compte->magasinDeSonIdentifiant()?->id]);
        }
    }

    /**
     * Un administrateur, même de tous les magasins et avec toutes les
     * cases et toutes les autorisations de société, ne crée jamais un
     * vendeur ou un observateur : ni par l'écran, ni par l'action.
     */
    public function test_un_administrateur_ne_cree_jamais_un_vendeur_ou_un_observateur(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, AutorisationsSociete::toutes(), PermissionRegistry::toutes());

        $this->dansLeMagasin($magasin, $administrateur);
        $this->get(EquipeDuMagasinResource::getUrl('index', ['tenant' => $magasin]))->assertForbidden();
        Livewire::test(ListEquipeDuMagasin::class)->assertForbidden();
        foreach ([RoleMagasin::Vendeur, RoleMagasin::Observateur] as $role) {
            $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($administrateur, $magasin, ['name' => 'Paul'], $role), AuthorizationException::class);
        }

        $this->assertFalse(User::query()->where('name', 'Paul')->exists());
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
        Livewire::test(ListEquipeDuMagasin::class)
            ->mountAction('creerUnCompte')
            ->assertActionDataSet(['name' => null])
            ->setActionData(['name' => 'Chef', 'role' => RoleMagasin::Responsable->value])
            ->callMountedAction();
        $liste = Livewire::test(ListEquipeDuMagasin::class);
        foreach (['edit', 'retirer_du_magasin', 'suspendre', 'redonner_l_acces'] as $geste) {
            $liste->assertTableActionHidden($geste, $affectationDuCoResponsable);
        }
        $this->forgerLActionDeLigne($liste, 'retirer_du_magasin', $affectationDuCoResponsable);
        $this->forgerLActionDeLigne($liste, 'suspendre', $affectationDuCoResponsable);
        $this->forgerLActionDeLigne($liste, 'nommer_responsable', Affectation::query()->where('user_id', $vendeur->id)->sole());
        $this->get(EquipeDuMagasinResource::getUrl('edit', ['record' => $affectationDuCoResponsable, 'tenant' => $magasin]))->assertForbidden();

        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($responsable, $vendeur, RoleMagasin::Responsable), AuthorizationException::class);
        foreach ([$coResponsable, $administrateur, $proprietaire] as $cible) {
            $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->suspendre($responsable, $cible), AuthorizationException::class);
            $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->retirer($responsable, $cible), AuthorizationException::class);
            $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->handle($responsable, $cible, true, 'un-mot-de-passe-solide'), AuthorizationException::class);
            $this->assertSame(StatutCompte::Actif, $cible->refresh()->statut, $cible->name);
        }

        $this->assertFalse(User::query()->where('name', 'Chef')->exists());
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
        $this->assertThrows(fn () => app(CreerExpertComptableAction::class)->handle($responsable, $magasin->societe, ['name' => 'Cabinet']), AuthorizationException::class);
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->suspendre($responsable, $expert), AuthorizationException::class);
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->retirer($responsable, $expert), AuthorizationException::class);
        $this->assertThrows(fn () => app(ModifierMembreAction::class)->handle($responsable, $expert, RoleMagasin::Vendeur), AuthorizationException::class);
        $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->handle($responsable, $expert, false), AuthorizationException::class);

        $this->assertSame([StatutCompte::Actif, true], [$expert->refresh()->statut, $expert->estExpertComptable()]);
        $this->assertFalse(User::query()->where('name', 'Cabinet')->exists());
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
        $this->forgerLActionDeLigne($liste, 'redonner_l_acces', $affectationDeB, ['mode' => 'absent']);
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->retirer($responsable, $vendeurDeB), AuthorizationException::class);
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->suspendre($responsable, $vendeurDeB), AuthorizationException::class);
        $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->handle($responsable, $vendeurDeB, false), AuthorizationException::class);

        $this->assertSame([StatutCompte::Actif, RoleMagasin::Vendeur, false], [$vendeurDeB->refresh()->statut, $affectationDeB->fresh()->role, $vendeurDeB->mot_de_passe_est_l_identifiant]);
    }

    public function test_deux_responsables_du_meme_magasin_ne_se_touchent_pas(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $premier = $this->responsable($magasin);
        $second = $this->responsable($magasin);

        foreach ([[$premier, $second], [$second, $premier]] as [$auteur, $cible]) {
            $this->assertFalse(app(PorteeDesDroits::class)->gereLeCompte($auteur, $cible));
            $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->retirer($auteur, $cible), AuthorizationException::class);
            $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->suspendre($auteur, $cible), AuthorizationException::class);
            $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->handle($auteur, $cible, false), AuthorizationException::class);
            $this->assertSame(RoleMagasin::Responsable, Affectation::query()->where('user_id', $cible->id)->sole()->role);
        }
    }

    /**
     * Le responsable de son magasin gère tout le compte d'un vendeur :
     * suspendre, réactiver, lui redonner l'accès, en sa présence (la
     * personne tape son mot de passe) et en son absence (il redevient son
     * identifiant), le retirer.
     */
    public function test_le_responsable_de_son_magasin_suspend_reactive_redonne_l_acces_et_retire_un_vendeur(): void
    {
        Notification::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $saLigne = Affectation::query()->where('user_id', $vendeur->id)->sole();

        $this->dansLeMagasin($magasin, $responsable);
        Livewire::test(ListEquipeDuMagasin::class)->callTableAction('suspendre', $saLigne->fresh());
        $this->assertTrue($vendeur->refresh()->estSuspendu());
        Livewire::test(ListEquipeDuMagasin::class)->callTableAction('reactiver', $saLigne->fresh());
        $this->assertTrue($vendeur->refresh()->estActif());
        Livewire::test(ListEquipeDuMagasin::class)
            ->callTableAction('redonner_l_acces', $saLigne->fresh(), ['mode' => 'present', 'mot_de_passe' => 'nouveau-mot-de-passe-de-paul', 'mot_de_passe_confirmation' => 'nouveau-mot-de-passe-de-paul'])
            ->assertHasNoTableActionErrors();
        $this->assertTrue(Hash::check('nouveau-mot-de-passe-de-paul', $vendeur->refresh()->password));
        $this->assertSame([false, true, (int) $responsable->id], [$vendeur->mot_de_passe_est_l_identifiant, $vendeur->acces_redonne_en_presence, (int) $vendeur->acces_redonne_par]);
        Livewire::test(ListEquipeDuMagasin::class)
            ->callTableAction('redonner_l_acces', $saLigne->fresh(), ['mode' => 'absent'])
            ->assertNotified("Accès redonné. Son identifiant, qui est de nouveau son mot de passe : {$vendeur->identifiantDeConnexion()}. Remettez-le-lui en main propre.");
        $this->assertTrue(Hash::check((string) $vendeur->identifiantDeConnexion(), $vendeur->refresh()->password));
        $this->assertSame([true, false], [$vendeur->mot_de_passe_est_l_identifiant, $vendeur->acces_redonne_en_presence]);
        Livewire::test(ListEquipeDuMagasin::class)->callTableAction('retirer_du_magasin', $saLigne->fresh());

        $this->assertSame(StatutCompte::Retire, $vendeur->refresh()->statut);
        $this->assertSame(0, Affectation::query()->where('user_id', $vendeur->id)->count());
    }

    /**
     * Un responsable ne change ni son affectation, ni son statut, ni son
     * accès : sa ligne n'a aucun bouton, sa fiche ne s'ouvre pas, et
     * l'action refuse.
     */
    public function test_un_responsable_ne_modifie_ni_son_affectation_ni_son_statut(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $saLigne = Affectation::query()->where('user_id', $responsable->id)->sole();

        $this->dansLeMagasin($magasin, $responsable);
        $liste = Livewire::test(ListEquipeDuMagasin::class)->assertCanSeeTableRecords([$saLigne]);
        foreach (['edit', 'retirer_du_magasin', 'suspendre', 'redonner_l_acces'] as $geste) {
            $liste->assertTableActionHidden($geste, $saLigne);
        }
        $this->get(EquipeDuMagasinResource::getUrl('edit', ['record' => $saLigne, 'tenant' => $magasin]))->assertForbidden();
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->retirer($responsable, $responsable), AuthorizationException::class);
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->suspendre($responsable, $responsable), AuthorizationException::class);
        $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->handle($responsable, $responsable, false), AuthorizationException::class);

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
        $this->assertThrows(fn () => app(ChangerStatutCompteAction::class)->retirer($vendeur, $collegue), AuthorizationException::class);
        $this->assertThrows(fn () => app(RedonnerLAccesAction::class)->handle($vendeur, $collegue, false), AuthorizationException::class);
        $this->assertThrows(fn () => app(CreerMembreAction::class)->handle($vendeur, $magasin, ['name' => 'Paul'], RoleMagasin::Observateur), AuthorizationException::class);

        $this->assertSame(StatutCompte::Actif, $collegue->refresh()->statut);
        $this->assertFalse(User::query()->where('name', 'Paul')->exists());
    }

    /**
     * Un compte de membre ne vaut que pour son magasin : le retirer de son
     * magasin le retire de la société, et la confirmation le dit avant.
     */
    public function test_retirer_un_vendeur_de_son_magasin_le_retire_de_la_societe_apres_confirmation(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul']);
        $saLigne = Affectation::query()->where('user_id', $vendeur->id)->sole();

        $this->dansLeMagasin($magasin, $responsable);
        Livewire::test(ListEquipeDuMagasin::class)
            ->mountTableAction('retirer_du_magasin', $saLigne)
            ->assertSee('Un compte ne vaut que pour son magasin : Paul sera retiré de la société.')
            ->callMountedTableAction();

        $this->assertSame([StatutCompte::Retire, (int) $responsable->id], [$vendeur->refresh()->statut, (int) $vendeur->statut_change_par]);
        $this->assertModelMissing($saLigne);
    }

    /**
     * La liste de l'équipe montre au responsable, pour les vendeurs et les
     * observateurs de son magasin, leur identifiant, les badges « Mot de
     * passe : l'identifiant » et « Jamais connecté », et filtre les mots de
     * passe encore égaux à l'identifiant.
     */
    public function test_la_liste_de_l_equipe_signale_au_responsable_un_mot_de_passe_encore_egal_a_l_identifiant_et_un_compte_jamais_connecte(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        [$nouveau, $identifiant] = $this->compteAuMotDePasseIdentifiant($magasin, RoleMagasin::Vendeur);
        $ancien = $this->membre($magasin, RoleMagasin::Observateur);
        $ancien->forceFill(['premiere_connexion_le' => now()->subDay(), 'derniere_connexion_le' => now()->subDay()])->save();
        $ligneDuNouveau = Affectation::query()->where('user_id', $nouveau->id)->sole();
        $ligneDeLAncien = Affectation::query()->where('user_id', $ancien->id)->sole();

        $this->dansLeMagasin($magasin, $responsable);
        Livewire::test(ListEquipeDuMagasin::class)
            ->assertTableColumnStateSet('identifiant', $identifiant, $ligneDuNouveau)
            ->assertTableColumnStateSet('signaux', ['Mot de passe : l\'identifiant', 'Jamais connecté'], $ligneDuNouveau)
            ->assertTableColumnStateSet('signaux', null, $ligneDeLAncien)
            ->filterTable('mot_de_passe_identifiant')
            ->assertCanSeeTableRecords([$ligneDuNouveau])
            ->assertCanNotSeeTableRecords([$ligneDeLAncien]);
    }

    /**
     * Un responsable ne voit l'identifiant que d'un vendeur ou d'un
     * observateur de son magasin, à qui il peut redonner l'accès : ni
     * celui d'un autre responsable, ni ses badges, ni par le filtre.
     */
    public function test_un_responsable_ne_voit_jamais_l_identifiant_d_un_compte_auquel_il_ne_peut_pas_redonner_l_acces(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $responsable = $this->responsable($magasin);
        [$coResponsable] = $this->compteAuMotDePasseIdentifiant($magasin, RoleMagasin::Responsable);
        $ligne = Affectation::query()->where('user_id', $coResponsable->id)->sole();

        $this->dansLeMagasin($magasin, $responsable);
        Livewire::test(ListEquipeDuMagasin::class)
            ->assertTableColumnStateNotSet('identifiant', (string) $coResponsable->identifiantDeConnexion(), $ligne)
            ->assertTableColumnStateSet('signaux', null, $ligne)
            ->assertDontSee((string) $coResponsable->identifiantDeConnexion())
            ->filterTable('mot_de_passe_identifiant')
            ->assertCanNotSeeTableRecords([$ligne]);
        $this->dansLeMagasin($magasin, $this->proprietaireDe($magasin));
        Livewire::test(ListEquipeDuMagasin::class)->assertTableColumnStateSet('identifiant', (string) $coResponsable->identifiantDeConnexion(), $ligne);
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
        $parLeProprietaire = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Paul']);
        $parLeResponsable = $this->membre($magasin, RoleMagasin::Vendeur, null, ['name' => 'Jean']);
        $dansLaSociete = $this->membre($magasin, RoleMagasin::Observateur, null, ['name' => 'Léa']);
        $this->passerEn($magasin, $situation);

        foreach ([[$proprietaire, $parLeProprietaire], [$responsable, $parLeResponsable]] as [$gestionnaire, $vendeur]) {
            $saLigne = Affectation::query()->where('user_id', $vendeur->id)->sole();
            $this->flushSession();
            $this->actingAs($gestionnaire)->get(EquipeDuMagasinResource::getUrl('index', ['tenant' => $magasin]))->assertOk()->assertSee($vendeur->name);
            $this->dansLeMagasin($magasin, $gestionnaire);
            $liste = Livewire::test(ListEquipeDuMagasin::class);

            if ($seGere) {
                $liste->assertActionVisible('creerUnCompte');
                $liste->callTableAction('retirer_du_magasin', $saLigne);
                $this->assertModelMissing($saLigne);
                $this->assertFalse($vendeur->refresh()->peutOuvrir($magasin));
            } else {
                $liste->assertActionHidden('creerUnCompte');
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
