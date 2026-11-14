<?php

namespace Tests\Feature\Societe;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\DesignerUnCabinetAction;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Tenancy\Actions\ChangerLeRoleDeSocieteAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\CreerEtablissementAction;
use App\Domain\Tenancy\Actions\CreerMembreAction;
use App\Domain\Tenancy\Actions\DemanderLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\ModifierIdentiteSocieteAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\StatutSociete;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\EquipeDuMagasinResource\Pages\ListEquipeDuMagasin;
use App\Filament\Cabinet\Pages\TableauDeBordDuCabinet;
use App\Filament\Societe\Pages\CabinetComptable;
use App\Filament\Societe\Pages\IdentiteSociete;
use App\Filament\Societe\Pages\ParametresFiscauxSociete;
use App\Filament\Societe\Pages\RattachementDUnCabinet;
use App\Filament\Societe\Pages\TableauDeBord;
use App\Filament\Societe\Pages\TelechargerLesDocuments;
use App\Filament\Societe\Pages\VerificationSociete;
use App\Filament\Societe\Resources\MagasinResource;
use App\Filament\Societe\Resources\MembreResource;
use App\Filament\Societe\Resources\MembreResource\Pages\FicheDuMembre;
use App\Filament\Societe\Resources\MembreResource\Pages\ListMembres;
use App\Filament\Societe\RessourceDuPanneauSociete;
use App\Models\User;
use App\Policies\SocietePolicy;
use Database\Factories\MagasinFactory;
use Database\Factories\SocieteFactory;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Le panneau de l'administration d'une société (voir SocietePanelProvider),
 * réservé à son propriétaire et à ses administrateurs, en mode
 * d'autorisation strict : chaque page
 * décide elle-même qui l'ouvre, chaque ressource hérite de
 * RessourceDuPanneauSociete et passe par une policy, et les champs de
 * choix n'y proposent des magasins ou des comptes que par une relation de
 * la société affichée. Rien de tout cela ne touche le panneau des
 * magasins.
 */
class PanneauSocieteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chaque page déclare sa propre règle d'accès (Filament ouvre sinon une
     * page à tous) et ne s'ouvre qu'au propriétaire actif de la société
     * affichée et à ses administrateurs actifs selon la page : le tableau
     * de bord et la vérification à tout administrateur, l'identité et la
     * fiscalité au seul administrateur de tous les magasins autorisé à
     * gérer les paramètres de la société, le cabinet comptable et la page
     * de rattachement d'un cabinet au propriétaire seul ; ni à un
     * responsable, un vendeur ou un compte du cabinet lié, ni au
     * propriétaire d'une autre société, ni à un compte suspendu, ni sans
     * connexion. Chaque ressource hérite de la base stricte, et le modèle
     * qu'elle gère a une policy explicite : sans elle, la Gate refuserait
     * tout.
     */
    public function test_chaque_ressource_et_page_du_panneau_societe_a_une_policy_complete(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $proprietaire = $this->proprietaireDe($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $responsable = $this->responsable($magasin);
        $collaborateur = $this->collaborateurDuCabinetLie($magasin);
        $administrateur = $this->administrateur($magasin, [$magasin->id]);
        $administrateurDesParametres = $this->administrateur($magasin, null, [AutorisationsSociete::PARAMETRES_GERER]);
        $proprietaireDUneAutre = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Autre Boutique']));
        $suspendu = $this->proprietaireDe($this->ouvrirUneSociete(['nom' => 'Boutique suspendue']));
        $suspendu->forceFill(['statut' => StatutCompte::Suspendu])->save();
        $panneau = Filament::getPanel('societe');
        $pages = $panneau->getPages();
        $pagesDesParametres = [IdentiteSociete::class, ParametresFiscauxSociete::class];
        $pagesDuProprietaire = [CabinetComptable::class, RattachementDUnCabinet::class];
        // « Télécharger les documents » : le propriétaire l'a d'office, un
        // administrateur seulement si le propriétaire la lui coche, ce que
        // le modèle de l'administrateur ne fait jamais (décision de
        // l'éditeur du 03/11/2026, E3).
        $pagesDesCasesDuProprietaire = [TelechargerLesDocuments::class];

        $this->assertNotEmpty($pages);
        $this->assertInstanceOf(SocietePolicy::class, Gate::getPolicyFor(Societe::class));

        foreach ($pages as $page) {
            /** @var class-string<Page> $page */
            $this->assertSame($page, (new ReflectionMethod($page, 'canAccess'))->getDeclaringClass()->getName(), $page);
            $this->assertSame(
                [
                    'proprietaire' => true,
                    'administrateur' => ! in_array($page, [...$pagesDesParametres, ...$pagesDuProprietaire, ...$pagesDesCasesDuProprietaire], true),
                    'administrateur_des_parametres' => ! in_array($page, [...$pagesDuProprietaire, ...$pagesDesCasesDuProprietaire], true),
                    'responsable' => false,
                    'vendeur' => false,
                    'collaborateur_du_cabinet_lie' => false,
                    'autre_societe' => false,
                    'sans_connexion' => false,
                ],
                [
                    'proprietaire' => $this->ouvre($page, $proprietaire, $magasin->societe),
                    'administrateur' => $this->ouvre($page, $administrateur, $magasin->societe),
                    'administrateur_des_parametres' => $this->ouvre($page, $administrateurDesParametres, $magasin->societe),
                    'responsable' => $this->ouvre($page, $responsable, $magasin->societe),
                    'vendeur' => $this->ouvre($page, $vendeur, $magasin->societe),
                    'collaborateur_du_cabinet_lie' => $this->ouvre($page, $collaborateur, $magasin->societe),
                    'autre_societe' => $this->ouvre($page, $proprietaireDUneAutre, $magasin->societe),
                    'sans_connexion' => $this->ouvre($page, null, $magasin->societe),
                ],
                $page,
            );
            $this->assertFalse($this->ouvre($page, $suspendu, $suspendu->societe), $page);
        }

        foreach ($panneau->getResources() as $ressource) {
            /** @var class-string<resource> $ressource */
            $this->assertTrue(is_subclass_of($ressource, RessourceDuPanneauSociete::class), $ressource);
            $this->assertFalse($ressource::shouldCheckPolicyExistence(), $ressource);
            $this->assertNotNull(Gate::getPolicyFor($ressource::getModel()), $ressource);
        }

        $this->assertFalse(RessourceDuPanneauSociete::shouldCheckPolicyExistence());
    }

    /**
     * Chaque compte sans espace d'administration, avec ce que l'adresse de
     * l'espace sous le uuid de sa propre société lui répond : un refus pour
     * un membre, l'espace de son cabinet pour un compte d'un cabinet.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function comptesSansEspaceSociete(): array
    {
        return [
            'responsable' => ['responsable', 'refus'],
            'vendeur' => ['vendeur', 'refus'],
            'observateur' => ['observateur', 'refus'],
            'propriétaire d\'un cabinet' => ['propriétaire d\'un cabinet', 'espace du cabinet'],
            'collaborateur' => ['collaborateur', 'espace du cabinet'],
        ];
    }

    /**
     * L'espace d'administration d'une société ne s'ouvre ni à un membre,
     * responsable compris, ni à un compte du cabinet lié, son propriétaire
     * ou un collaborateur, qui a l'espace de son cabinet : 403 pour la
     * société cliente ; pour sa propre société, 403 pour un membre, et
     * l'espace de son cabinet pour un compte d'un cabinet ; le panneau ne
     * leur propose aucune société.
     */
    #[DataProvider('comptesSansEspaceSociete')]
    public function test_ni_un_membre_ni_un_compte_de_cabinet_n_ouvrent_l_espace_societe(string $compte, string $saSociete): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $visiteur = match ($compte) {
            'responsable' => $this->responsable($magasin),
            'vendeur' => $this->membre($magasin, RoleMagasin::Vendeur),
            'observateur' => $this->membre($magasin, RoleMagasin::Observateur),
            'propriétaire d\'un cabinet' => $this->collaborateurDuCabinetLie($magasin)->societe->proprietaire()->firstOrFail(),
            'collaborateur' => $this->collaborateurDuCabinetLie($magasin),
        };

        $this->assertFalse($visiteur->canAccessPanel(Filament::getPanel('societe')));
        $this->assertSame([], $visiteur->getTenants(Filament::getPanel('societe'))->modelKeys());
        $this->actingAs($visiteur)->get(TableauDeBord::getUrl(panel: 'societe', tenant: $magasin->societe))->assertForbidden();
        $reponse = $this->get(TableauDeBord::getUrl(panel: 'societe', tenant: $visiteur->societe));
        match ($saSociete) {
            'refus' => $reponse->assertForbidden(),
            'espace du cabinet' => $reponse->assertRedirect(TableauDeBordDuCabinet::getUrl(panel: 'cabinet', tenant: $visiteur->societe)),
        };
    }

    /**
     * Un administrateur de tous les magasins à qui le propriétaire a coché
     * les paramètres de la société ouvre son identité et ses réglages
     * fiscaux, et les modifie.
     */
    public function test_un_administrateur_avec_parametres_ouvre_l_identite(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $administrateur = $this->administrateur($magasin, null, [AutorisationsSociete::PARAMETRES_GERER]);

        $this->actingAs($administrateur);
        $this->get(IdentiteSociete::getUrl(panel: 'societe', tenant: $magasin->societe))->assertOk();
        $this->get(ParametresFiscauxSociete::getUrl(panel: 'societe', tenant: $magasin->societe))->assertOk();
        app(TenantContext::class)->clear();
        app(ModifierIdentiteSocieteAction::class)->handle($administrateur, $magasin->societe, ['capital_social' => '5000']);

        $this->assertSame('5000.00', (string) $magasin->societe->fresh()->capital_social);
    }

    /**
     * Sans l'autorisation des paramètres, ni un administrateur de tous les
     * magasins ni celui d'une sélection n'ouvrent l'identité ou la
     * fiscalité, ni ne les modifient par l'action.
     */
    public function test_un_administrateur_sans_parametres_n_ouvre_ni_l_identite_ni_la_fiscalite(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $deTous = $this->administrateur($magasin, null, [AutorisationsSociete::MAGASINS_GERER, AutorisationsSociete::RESPONSABLES_NOMMER]);
        $dUneSelection = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::MAGASINS_GERER, AutorisationsSociete::RESPONSABLES_NOMMER]);
        $capital = $magasin->societe->capital_social;

        foreach ([$deTous, $dUneSelection] as $administrateur) {
            $this->flushSession();
            $this->actingAs($administrateur);
            $this->get(TableauDeBord::getUrl(panel: 'societe', tenant: $magasin->societe))->assertOk();
            $this->get(IdentiteSociete::getUrl(panel: 'societe', tenant: $magasin->societe))->assertForbidden();
            $this->get(ParametresFiscauxSociete::getUrl(panel: 'societe', tenant: $magasin->societe))->assertForbidden();
            app(TenantContext::class)->clear();
            $this->assertThrows(fn () => app(ModifierIdentiteSocieteAction::class)->handle($administrateur, $magasin->societe, ['capital_social' => '5000']), LogicException::class, ModifierIdentiteSocieteAction::MESSAGE_SANS_AUTORISATION);
        }

        $this->assertSame($capital, $magasin->societe->fresh()->capital_social);
    }

    /**
     * Les magasins et les comptes que proposent les formulaires de l'espace
     * société sont ceux de la société affichée, et, pour un administrateur,
     * de sa portée : jamais ceux d'une autre société, ni ceux d'un magasin
     * hors de sa sélection ; un magasin forcé hors de ces choix est
     * refusé.
     */
    public function test_les_options_des_formulaires_et_filtres_se_limitent_a_la_societe_et_a_la_portee(): void
    {
        $ailleurs = $this->ouvrirUneSociete(['nom' => 'Ailleurs']);
        $vendeurDAilleurs = $this->membre($ailleurs, RoleMagasin::Vendeur);
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon Centre']);
        $horsPortee = $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Villeurbanne']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $vendeurHorsPortee = $this->membre($horsPortee, RoleMagasin::Vendeur);
        $administrateur = $this->administrateur($magasin, [$magasin->id], [AutorisationsSociete::RESPONSABLES_NOMMER]);

        $this->dansLEspaceDeLaSociete($magasin->societe, $this->proprietaireDe($magasin));
        $this->assertEqualsCanonicalizing([$magasin->id, $horsPortee->id], array_keys(MembreResource::magasinsDeLaPortee()));

        $this->dansLEspaceDeLaSociete($magasin->societe, $administrateur);
        $this->assertSame([$magasin->id], array_keys(MembreResource::magasinsDeLaPortee()));
        $candidats = app(PorteeDesDroits::class)->candidatsResponsables($administrateur, $magasin)->pluck('users.id')->all();
        $this->assertSame([$vendeur->id], array_map(intval(...), $candidats));
        $this->assertSame([], app(PorteeDesDroits::class)->candidatsResponsables($administrateur, $horsPortee)->pluck('users.id')->all());
        $this->assertNotContains($vendeurDAilleurs->id, MembreResource::getEloquentQuery()->pluck('users.id')->all());
        $this->assertNotContains($vendeurHorsPortee->id, MembreResource::getEloquentQuery()->pluck('users.id')->all());

        $this->forgerLAction(Livewire::test(ListMembres::class), 'nommerUnResponsable', ['magasin_id' => $horsPortee->id, 'compte' => 'existant', 'candidat' => $vendeurHorsPortee->id]);
        $this->forgerLAction(Livewire::test(ListMembres::class), 'nommerUnResponsable', ['magasin_id' => $ailleurs->id, 'compte' => 'existant', 'candidat' => $vendeurDAilleurs->id]);

        $this->assertSame(RoleMagasin::Vendeur, Affectation::query()->where('user_id', $vendeurHorsPortee->id)->sole()->role);
        $this->assertSame(RoleMagasin::Vendeur, Affectation::query()->where('user_id', $vendeurDAilleurs->id)->sole()->role);
    }

    /**
     * Une société suspendue garde son espace consultable et son équipe
     * gérable, mais n'y crée plus de magasin et n'y modifie plus ses
     * paramètres.
     */
    public function test_une_societe_suspendue_garde_son_espace_consultable_mais_ne_cree_rien(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $societe = $magasin->societe;
        $proprietaire = $this->proprietaireDe($magasin);
        $societe->forceFill(['statut' => StatutSociete::Suspendue])->save();
        $magasins = Magasin::query()->where('societe_id', $societe->id)->count();

        $this->actingAs($proprietaire);
        $this->get(TableauDeBord::getUrl(panel: 'societe', tenant: $societe))->assertOk();
        $this->get(IdentiteSociete::getUrl(panel: 'societe', tenant: $societe))->assertOk();
        $this->get(MembreResource::getUrl('index', panel: 'societe', tenant: $societe))->assertOk();
        app(TenantContext::class)->clear();
        $this->assertThrows(fn () => app(CreerEtablissementAction::class)->handle($proprietaire, $societe, ['nom' => 'Lyon 2', 'nic' => MagasinFactory::nicLibre($societe)]), LogicException::class, CreerEtablissementAction::MESSAGE_SANS_AUTORISATION);
        $this->assertThrows(fn () => app(ModifierIdentiteSocieteAction::class)->handle($proprietaire, $societe, ['capital_social' => '5000']), LogicException::class, ModifierIdentiteSocieteAction::MESSAGE_SANS_AUTORISATION);
        $compte = app(CreerMembreAction::class)->handle($proprietaire, $magasin, ['name' => 'Paul'], RoleMagasin::Vendeur);

        $this->assertSame($magasins, Magasin::query()->where('societe_id', $societe->id)->count());
        $this->assertTrue($compte->exists);
    }

    /**
     * Une société révoquée (un usurpateur) garde son espace et son équipe
     * consultables, mais plus rien ne s'y gère, ni par l'écran ni par les
     * actions : aucun compte créé, suspendu, nommé, réintégré, changé de
     * rôle ou dont l'accès est redonné, aucun administrateur, aucune
     * transmission de la propriété demandée, aucun cabinet désigné.
     */
    public function test_une_societe_revoquee_ne_gere_plus_son_equipe(): void
    {
        Mail::fake();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $societe = $magasin->societe;
        $proprietaire = $this->proprietaireDe($magasin);
        $responsable = $this->responsable($magasin);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $administrateur = $this->administrateurPretAReprendre($magasin);
        $vendeurRetire = $this->membre($magasin, RoleMagasin::Vendeur);
        $cabinet = $this->ouvrirUnCabinet();
        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $vendeurRetire);
        $societe->forceFill(['statut' => StatutSociete::Revoquee])->save();
        app(TenantContext::class)->clear();
        $avant = User::query()->where('societe_id', $societe->id)->orderBy('id')->get(['id', 'role_societe', 'statut'])->toArray();
        $affectations = Affectation::query()->where('societe_id', $societe->id)->count();

        $gestes = [
            fn () => app(CreerMembreAction::class)->handle($proprietaire, $magasin, ['name' => 'Paul'], RoleMagasin::Vendeur),
            fn () => app(CreerMembreAction::class)->handle($responsable, $magasin, ['name' => 'Jean'], RoleMagasin::Vendeur),
            fn () => app(ChangerStatutCompteAction::class)->suspendre($proprietaire, $vendeur),
            fn () => app(ModifierMembreAction::class)->handle($proprietaire, $vendeur, RoleMagasin::Responsable),
            fn () => app(ChangerStatutCompteAction::class)->reintegrer($proprietaire, $vendeurRetire, RoleMagasin::Vendeur),
            fn () => app(ChangerLeRoleDeSocieteAction::class)->versAdministrateur($proprietaire, $vendeur, null, [], []),
            fn () => app(RedonnerLAccesAction::class)->handle($proprietaire, $vendeur, false),
            fn () => app(CreerAdministrateurAction::class)->handle($proprietaire, $societe, ['name' => 'Alice'], null, [], []),
            fn () => app(DemanderLaTransmissionDeLaProprieteAction::class)->handle($proprietaire, $administrateur, 'password'),
            fn () => app(DesignerUnCabinetAction::class)->handle($proprietaire, $societe, $cabinet),
        ];
        foreach ($gestes as $geste) {
            $this->assertThrows($geste, AuthorizationException::class);
        }

        $this->assertSame($avant, User::query()->where('societe_id', $societe->id)->orderBy('id')->get(['id', 'role_societe', 'statut'])->toArray());
        $this->assertSame($affectations, Affectation::query()->where('societe_id', $societe->id)->count());
        $this->assertSame(0, LienCabinet::query()->withoutGlobalScopes()->where('societe_id', $societe->id)->count());
        $this->dansLeMagasin($magasin, $responsable);
        Livewire::test(ListEquipeDuMagasin::class)->assertActionHidden('creerUnCompte');
        $this->dansLEspaceDeLaSociete($societe, $proprietaire);
        Livewire::test(ListMembres::class)
            ->assertCanSeeTableRecords([$vendeur])
            ->assertActionHidden('creerUnMembre')
            ->assertActionHidden('creerUnAdministrateur');
        Livewire::test(FicheDuMembre::class, ['record' => $administrateur->getRouteKey()])->assertActionHidden('transmettreLaPropriete');
        Mail::assertNothingOutgoing();
    }

    /**
     * Le tableau de bord d'un administrateur d'une sélection ne montre que
     * les magasins de sa sélection ; celui du propriétaire, tous.
     */
    public function test_le_tableau_de_bord_d_un_administrateur_d_une_selection_ne_montre_que_ses_magasins(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon Centre']);
        $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Villeurbanne Gratte-Ciel']);
        $administrateur = $this->administrateur($magasin, [$magasin->id]);

        $this->actingAs($administrateur)
            ->get(TableauDeBord::getUrl(panel: 'societe', tenant: $magasin->societe))
            ->assertOk()
            ->assertSeeText('Lyon Centre')
            ->assertDontSeeText('Villeurbanne Gratte-Ciel');
        $this->flushSession();
        $this->actingAs($this->proprietaireDe($magasin))
            ->get(TableauDeBord::getUrl(panel: 'societe', tenant: $magasin->societe))
            ->assertOk()
            ->assertSeeText('Lyon Centre')
            ->assertSeeText('Villeurbanne Gratte-Ciel');
    }

    /**
     * Le tableau de bord de la société dit, après « Bonjour » et le nom de
     * la personne, son rôle dans la société ; à un administrateur autorisé
     * à nommer les responsables, et à lui seul, où le faire : dans
     * « Comptes ».
     */
    public function test_le_tableau_de_bord_de_la_societe_dit_bonjour_avec_le_role(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Lyon Centre', 'raison_sociale' => 'Ma Boutique SAS'], User::factory()->create(['name' => 'Jeanne Dupont']));
        $autorise = $this->administrateur($magasin, autorisationsSociete: [AutorisationsSociete::RESPONSABLES_NOMMER]);
        $nonAutorise = $this->administrateur($magasin);
        $tableauDeBord = TableauDeBord::getUrl(panel: 'societe', tenant: $magasin->societe);

        $this->actingAs($this->proprietaireDe($magasin))
            ->get($tableauDeBord)
            ->assertSeeTextInOrder(['Bonjour Jeanne Dupont', 'Propriétaire de Ma Boutique SAS', 'Vos magasins'])
            ->assertDontSeeText('Nommer un responsable');
        $this->flushSession();
        $this->actingAs($autorise)
            ->get($tableauDeBord)
            ->assertSeeTextInOrder(['Bonjour '.$autorise->name, 'Administrateur', 'Nommer un responsable : Comptes'])
            ->assertSee(htmlspecialchars(MembreResource::getUrl('index', panel: 'societe', tenant: $magasin->societe)), escape: false);
        $this->flushSession();
        $this->actingAs($nonAutorise)
            ->get($tableauDeBord)
            ->assertSeeTextInOrder(['Bonjour '.$nonAutorise->name, 'Administrateur'])
            ->assertDontSeeText('Nommer un responsable');
    }

    /**
     * Le tableau de bord du propriétaire coche chaque première étape
     * faite : le premier magasin créé, un compte ajouté à l'équipe, la
     * démonstration essayée, la société validée ; chacune mène à son écran
     * (la création d'un magasin, avant comme après le premier, sous le
     * titre « Ajouter un magasin » dès qu'il existe), et la liste disparaît
     * quand tout est fait. Un administrateur n'a pas de premières étapes.
     */
    public function test_le_tableau_de_bord_coche_chaque_premiere_etape_faite(): void
    {
        $societe = $this->inscrireUneSociete();
        $proprietaire = $societe->proprietaire()->firstOrFail();
        $administrateur = $this->choisirSonMotDePasse(app(CreerAdministrateurAction::class)->handle($proprietaire, $societe, ['name' => 'Alice Martin'], null, PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce), []));
        $etapes = function (User $compte) use ($societe): array {
            $this->dansLEspaceDeLaSociete($societe->fresh(), $compte);

            return collect(Livewire::test(TableauDeBord::class)->instance()->etapes())->mapWithKeys(fn (array $etape): array => [$etape['titre'] => [$etape['faite'], $etape['url']]])->all();
        };

        $this->assertSame([
            TableauDeBord::ETAPE_CODE_DU_COURRIER => [false, VerificationSociete::getUrl(panel: 'societe', tenant: $societe)],
            TableauDeBord::ETAPE_PREMIER_MAGASIN => [false, MagasinResource::getUrl('create', panel: 'societe', tenant: $societe)],
            TableauDeBord::ETAPE_EQUIPE => [true, MembreResource::getUrl('index', panel: 'societe', tenant: $societe)],
            TableauDeBord::ETAPE_DEMONSTRATION => [false, null],
        ], $etapes($proprietaire));
        $this->assertSame([], $etapes($administrateur));

        $magasin = $this->creerLeMagasinDuSiege($societe);
        $demonstration = app(OuvrirLaDemonstrationAction::class)->handle($proprietaire, $societe->fresh());

        $this->assertSame(
            [[false, VerificationSociete::getUrl(panel: 'societe', tenant: $societe)], [true, MagasinResource::getUrl('create', panel: 'societe', tenant: $societe)], [true, Dashboard::getUrl(panel: 'app', tenant: $demonstration)]],
            array_values(array_intersect_key($etapes($proprietaire), array_flip([TableauDeBord::ETAPE_CODE_DU_COURRIER, TableauDeBord::ETAPE_AJOUTER_UN_MAGASIN, TableauDeBord::ETAPE_DEMONSTRATION]))),
        );
        $this->get(TableauDeBord::getUrl(panel: 'societe', tenant: $societe))->assertSeeText('Vos premières étapes');

        $this->saisirLeCodeDuCourrier($societe->fresh());

        $this->assertSame([TableauDeBord::ETAPE_CODE_DU_COURRIER => true, TableauDeBord::ETAPE_AJOUTER_UN_MAGASIN => true, TableauDeBord::ETAPE_EQUIPE => true], array_map(fn (array $etape): bool => $etape[0], $etapes($proprietaire)));
        $this->get(TableauDeBord::getUrl(panel: 'societe', tenant: $societe))->assertOk()->assertDontSeeText('Vos premières étapes')->assertSeeText($magasin->nom);
    }

    /**
     * L'étape des magasins s'intitule « Créer votre premier magasin » tant
     * que la société n'en a aucun réel, puis « Ajouter un magasin », cochée,
     * avec le nombre de magasins dessous, sans ordinal ; elle mène toujours
     * à la création d'un magasin pour le propriétaire (décision de
     * l'éditeur du 01/11/2026).
     */
    public function test_l_etape_des_magasins_dit_creer_votre_premier_magasin_puis_ajouter_un_magasin(): void
    {
        $societe = $this->inscrireUneSociete();
        $proprietaire = $societe->proprietaire()->firstOrFail();
        $creation = MagasinResource::getUrl('create', panel: 'societe', tenant: $societe);
        $etapeDesMagasins = function () use ($societe, $proprietaire): array {
            $this->dansLEspaceDeLaSociete($societe->fresh(), $proprietaire);

            return collect(Livewire::test(TableauDeBord::class)->instance()->etapes())->firstWhere('titre', '!=', TableauDeBord::ETAPE_CODE_DU_COURRIER);
        };

        $this->assertSame(['titre' => TableauDeBord::ETAPE_PREMIER_MAGASIN, 'faite' => false, 'etat' => 'Choisissez son établissement dans la liste de ceux de votre SIREN.', 'url' => $creation], $etapeDesMagasins());

        $magasin = $this->creerLeMagasinDuSiege($societe);
        $this->assertSame(['titre' => TableauDeBord::ETAPE_AJOUTER_UN_MAGASIN, 'faite' => true, 'etat' => '1 magasin', 'url' => $creation], $etapeDesMagasins());
        $this->actingAs($proprietaire)->get(TableauDeBord::getUrl(panel: 'societe', tenant: $societe))
            ->assertSeeTextInOrder(['Vos premières étapes', 'Ajouter un magasin', '1 magasin'])
            ->assertDontSeeText(TableauDeBord::ETAPE_PREMIER_MAGASIN);

        $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Lyon 2']);
        $this->ouvrirUnAutreMagasin($magasin, ['nom' => 'Lyon 3']);
        $this->assertSame(['titre' => TableauDeBord::ETAPE_AJOUTER_UN_MAGASIN, 'faite' => true, 'etat' => '3 magasins', 'url' => $creation], $etapeDesMagasins());
    }

    /**
     * Les comptes de l'expert-comptable déclaré par la société ne sont pas
     * l'équipe de ses magasins : une déclaration seule laisse l'étape
     * « Ajouter votre équipe » à faire (R22 du cahier du lot EC2).
     */
    public function test_une_declaration_seule_ne_coche_pas_l_etape_de_l_equipe(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $this->declarerSonExpertComptable($magasin);
        $etapeDeLEquipe = function () use ($magasin): array {
            $this->dansLEspaceDeLaSociete($magasin->societe->fresh(), $this->proprietaireDe($magasin));

            return collect(Livewire::test(TableauDeBord::class)->instance()->etapes())->firstWhere('titre', TableauDeBord::ETAPE_EQUIPE);
        };

        $this->assertFalse($etapeDeLEquipe()['faite']);

        $this->membre($magasin, RoleMagasin::Vendeur);
        $this->assertSame([true, '1 compte en plus du vôtre'], [$etapeDeLEquipe()['faite'], $etapeDeLEquipe()['etat']]);
    }

    /**
     * Une société validée par l'invitation acceptée de son cabinet n'a
     * jamais reçu de courrier ni eu de démonstration : ses premières étapes
     * sont seulement celles des magasins et de l'équipe, et son tableau de
     * bord comme sa page « Vérification » disent seulement qu'elle est
     * validée par l'invitation de son cabinet, sans courrier, ni code, ni
     * démonstration, ni lien ou bouton qui y mène (décision de l'éditeur du
     * 01/11/2026). Une société qui s'inscrit elle-même a ses quatre étapes
     * avant sa validation, puis trois : la démonstration disparaît de tous
     * ses écrans une fois la société validée (T115, lot DEMO-V).
     */
    public function test_une_societe_validee_par_l_invitation_de_son_cabinet_ne_voit_ni_l_etape_du_courrier_ni_celle_de_la_demonstration(): void
    {
        $this->travelTo(self::aParis('2026-11-03 10:00:00'));
        $proprietaire = $this->accepterLInvitation($this->inviterUneEntreprise($this->ouvrirUnCabinet(), SocieteFactory::sirenFictif(), 'dirigeant@entreprise.test'));
        $invitee = $proprietaire->societe()->firstOrFail();
        $phrase = 'Votre société est validée depuis le 03/11/2026, par l\'invitation de votre cabinet d\'expertise comptable.';
        $absents = ['courrier', 'code', 'démonstration', TableauDeBord::ETAPE_CODE_DU_COURRIER, TableauDeBord::ETAPE_DEMONSTRATION];

        $this->dansLEspaceDeLaSociete($invitee, $proprietaire);
        $page = Livewire::test(TableauDeBord::class);
        $this->assertSame([TableauDeBord::ETAPE_PREMIER_MAGASIN, TableauDeBord::ETAPE_EQUIPE], array_column($page->instance()->etapes(), 'titre'));
        $page->assertActionHidden('ouvrirLaDemonstration');
        $this->actingAs($proprietaire)->get(TableauDeBord::getUrl(panel: 'societe', tenant: $invitee))
            ->assertOk()
            ->assertSeeText(['Vos premières étapes', TableauDeBord::ETAPE_PREMIER_MAGASIN, TableauDeBord::ETAPE_EQUIPE, $phrase])
            ->assertDontSeeText($absents)
            ->assertDontSee(route('code.afficher'));
        $this->get(VerificationSociete::getUrl(panel: 'societe', tenant: $invitee))
            ->assertOk()
            ->assertSeeText($phrase)
            ->assertDontSeeText(['courrier', 'code', 'démonstration'])
            ->assertDontSee(route('code.afficher'));

        $inscrite = $this->inscrireUneSociete();
        $sonProprietaire = $inscrite->proprietaire()->firstOrFail();
        $this->dansLEspaceDeLaSociete($inscrite->fresh(), $sonProprietaire);
        $this->assertSame([TableauDeBord::ETAPE_CODE_DU_COURRIER, TableauDeBord::ETAPE_PREMIER_MAGASIN, TableauDeBord::ETAPE_EQUIPE, TableauDeBord::ETAPE_DEMONSTRATION], array_column(Livewire::test(TableauDeBord::class)->instance()->etapes(), 'titre'));
        $this->saisirLeCodeDuCourrier($inscrite->fresh());
        $this->dansLEspaceDeLaSociete($inscrite->fresh(), $sonProprietaire);
        $this->assertSame([TableauDeBord::ETAPE_CODE_DU_COURRIER, TableauDeBord::ETAPE_PREMIER_MAGASIN, TableauDeBord::ETAPE_EQUIPE], array_column(Livewire::test(TableauDeBord::class)->instance()->etapes(), 'titre'));
        $this->flushSession();
        $this->actingAs($sonProprietaire)->get(VerificationSociete::getUrl(panel: 'societe', tenant: $inscrite))
            ->assertOk()
            ->assertSeeText('Votre société est validée depuis le 03/11/2026.')
            ->assertDontSeeText('par l\'invitation de votre cabinet');
    }

    /**
     * Les premières étapes ne comptent que dans la portée du propriétaire,
     * sans son propre compte ; un administrateur d'une sélection, même
     * autorisé à nommer les responsables, n'y voit ni le nombre des
     * magasins de la société, ni celui de ses comptes, ni l'état de sa
     * démonstration : seulement les magasins de sa sélection.
     */
    public function test_les_premieres_etapes_ne_montrent_a_un_administrateur_aucun_nombre_hors_de_sa_portee(): void
    {
        $lyon = $this->ouvrirUneSociete(['nom' => 'Lyon Centre', 'a_verifier' => true]);
        $paris = $this->ouvrirUnAutreMagasin($lyon, ['nom' => 'Paris Bastille']);
        $this->membre($paris, RoleMagasin::Vendeur);
        $this->membre($paris, RoleMagasin::Vendeur);
        $administrateur = $this->administrateur($lyon, [$lyon->id], [AutorisationsSociete::RESPONSABLES_NOMMER]);
        app(OuvrirLaDemonstrationAction::class)->handle($this->proprietaireDe($lyon), $lyon->societe);
        $tableauDeBord = TableauDeBord::getUrl(panel: 'societe', tenant: $lyon->societe);

        $this->actingAs($administrateur)
            ->get($tableauDeBord)
            ->assertOk()
            ->assertSeeText('Lyon Centre')
            ->assertDontSeeText(['Vos premières étapes', 'Paris Bastille', '2 magasins', 'en plus du vôtre', 'Ouvert : vous vous y entraînez']);
        $this->flushSession();
        $this->actingAs($this->proprietaireDe($lyon))
            ->get($tableauDeBord)
            ->assertOk()
            ->assertSeeText(['Vos premières étapes', '2 magasins', '3 comptes en plus du vôtre', 'Ouvert : vous vous y entraînez']);
    }

    /**
     * Le mode strict est redéclaré sur la base des ressources de la
     * société : il ne change rien aux ressources du panneau des magasins,
     * qui contrôlent toujours l'existence de leur policy.
     */
    public function test_les_ressources_du_panneau_magasin_gardent_le_controle_d_existence_des_policies(): void
    {
        $ressources = Filament::getPanel('app')->getResources();

        $this->assertNotEmpty($ressources);

        foreach ($ressources as $ressource) {
            /** @var class-string<resource> $ressource */
            $this->assertFalse(is_subclass_of($ressource, RessourceDuPanneauSociete::class), $ressource);
            $this->assertTrue($ressource::shouldCheckPolicyExistence(), $ressource);
        }

        $this->assertTrue(Resource::shouldCheckPolicyExistence());
    }

    /**
     * Le garde des champs de choix (voir
     * AppServiceProvider::garderLesChoixDuPanneauSociete()) ne vaut que
     * dans le panneau société : il y refuse des magasins ou des comptes
     * proposés sans passer par la société affichée, et laisse intacts les
     * champs du panneau des magasins.
     */
    public function test_les_options_du_panneau_magasin_ne_sont_pas_touchees_par_les_gardes_du_panneau_societe(): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $autreSociete = $this->ouvrirUneSociete(['nom' => 'Autre Boutique'])->societe;
        $contexte = app(TenantContext::class);

        Filament::setCurrentPanel(Filament::getPanel('app'));
        Filament::setTenant($magasin, isQuiet: true);
        $contexte->set($magasin);

        $this->assertSame([], Select::make('operateur')->model(EnregistrementFiscal::class)->relationship('operateur', 'name')->getExtraAttributes());
        $this->assertSame([], CheckboxList::make('vendeurs')->model($this->magasinEtSesComptes($magasin))->relationship('comptes', 'name')->getExtraAttributes());

        $contexte->clear();
        Filament::setCurrentPanel(Filament::getPanel('societe'));
        Filament::setTenant($magasin->societe, isQuiet: true);
        $contexte->poserSociete($magasin->societe);

        $this->assertSame([], Select::make('magasin')->model($magasin->societe)->relationship('magasins', 'nom')->getExtraAttributes());
        $this->assertSame([], Select::make('membre')->model($magasin->societe)->relationship('membres', 'name')->getExtraAttributes());
        $this->assertThrows(
            fn () => Select::make('operateur')->model(EnregistrementFiscal::class)->relationship('operateur', 'name')->getExtraAttributes(),
            LogicException::class,
            'Le champ « operateur » de l\'espace société propose des magasins ou des comptes sans passer par une relation de la société.',
        );
        $this->assertThrows(
            fn () => CheckboxList::make('vendeurs')->model($this->magasinEtSesComptes($magasin))->relationship('comptes', 'name')->getExtraAttributes(),
            LogicException::class,
        );
        $this->assertThrows(
            fn () => Select::make('magasin')->model($autreSociete)->relationship('magasins', 'nom')->getExtraAttributes(),
            LogicException::class,
        );
    }

    /**
     * Le magasin, vu par un modèle qui propose ses comptes par une relation
     * plusieurs-à-plusieurs (ses affectations), sans passer par la société.
     */
    private function magasinEtSesComptes(Magasin $magasin): Model
    {
        $modele = new class extends Model
        {
            protected $table = 'magasins';

            /**
             * @return BelongsToMany<User, $this>
             */
            public function comptes(): BelongsToMany
            {
                return $this->belongsToMany(User::class, 'affectations', 'magasin_id', 'user_id');
            }
        };

        return $modele->newFromBuilder(['id' => $magasin->id]);
    }

    /**
     * La page s'ouvre-t-elle à ce compte, dans l'administration de cette
     * société ?
     *
     * @param  class-string<Page>  $page
     */
    private function ouvre(string $page, ?User $compte, Societe $societe): bool
    {
        auth()->logout();

        if ($compte !== null) {
            $this->actingAs($compte);
        }

        Filament::setCurrentPanel(Filament::getPanel('societe'));
        Filament::setTenant($societe, isQuiet: true);
        app(TenantContext::class)->clear();
        app(TenantContext::class)->poserSociete($societe);

        return $page::canAccess();
    }
}
