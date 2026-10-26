<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Actions\TrouverOuCreerServiceAction;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Tenancy\Actions\AccepterLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\ChangerLeRoleDeSocieteAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\CreerAdministrateurAction;
use App\Domain\Tenancy\Actions\ModifierAdministrateurAction;
use App\Domain\Tenancy\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\EquipeDuMagasinResource;
use App\Filament\Societe\Resources\MembreResource;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les autorisations de magasin par famille (voir
 * PermissionRegistry::articles() et pourLeType()) : chacune dans une seule
 * famille, portée par le référentiel en base ; dans une société de
 * services, qui n'a pas d'articles, aucune autorisation de la famille des
 * articles n'existe, ni pour un rôle, ni dans les cases proposées,
 * précochées ou écrites, ni au journal des événements, et la base refuse
 * de la cocher ; les services ont leurs propres autorisations ; le
 * commerce garde toutes les autorisations d'aujourd'hui.
 */
class AutorisationsSelonLeTypeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * La famille des articles, en toutes lettres : 18 autorisations.
     */
    private const ARTICLES = [
        'fournisseur.view', 'fournisseur.create', 'fournisseur.update', 'fournisseur.archiver',
        'produit.view', 'produit.create', 'produit.update', 'produit.archiver', 'produit.ajuster_stock', 'produit.voir_valeur_stock',
        'prix_vente.regler', 'stock.vendre_sans_stock',
        'facture_achat.view', 'facture_achat.creer', 'facture_achat.valider',
        'avoir_fournisseur.view', 'avoir_fournisseur.creer',
        'facture.justifier',
    ];

    public function test_chaque_autorisation_est_dans_une_seule_famille_et_le_referentiel_en_base_porte_la_sienne(): void
    {
        $communes = array_values(array_diff(PermissionRegistry::toutes(), PermissionRegistry::articles()));

        $this->assertEqualsCanonicalizing(self::ARTICLES, PermissionRegistry::articles());
        $this->assertSame([47, 18, 29], [count(PermissionRegistry::toutes()), count(PermissionRegistry::articles()), count($communes)]);
        $this->assertSame([], array_values(array_diff(PermissionRegistry::articles(), PermissionRegistry::toutes())));
        $this->assertContains(PermissionRegistry::SERVICE_VIEW, $communes);
        $this->assertContains(PermissionRegistry::SERVICE_CREATE, $communes);
        $this->assertContains(PermissionRegistry::SERVICE_UPDATE, $communes);

        $familles = DB::table('referentiel_autorisations_magasin')->pluck('famille', 'cle')->all();
        $this->assertEqualsCanonicalizing(PermissionRegistry::toutes(), array_keys($familles));
        foreach (PermissionRegistry::toutes() as $cle) {
            $this->assertSame(in_array($cle, self::ARTICLES, true) ? 'articles' : 'commune', $familles[$cle], $cle);
        }
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('referentiel_autorisations_magasin')->where('cle', PermissionRegistry::CLIENT_VIEW)->update(['famille' => 'autre'])), QueryException::class);
    }

    /**
     * Dans une société de services, aucun rôle n'a d'autorisation de la
     * famille des articles : ni le propriétaire, ni un responsable, ni un
     * administrateur au modèle, ni un vendeur ou un observateur, ni un
     * expert-comptable ; chacun garde celles de la famille commune.
     */
    public function test_sans_articles_aucun_role_n_a_d_autorisation_d_articles(): void
    {
        $magasin = $this->ouvrirUneSociete(['type' => TypeDActivite::Services]);
        $comptes = [
            'propriétaire' => $this->proprietaireDe($magasin),
            'responsable' => $this->responsable($magasin),
            'administrateur' => $this->administrateur($magasin),
            'vendeur' => $this->membre($magasin, RoleMagasin::Vendeur),
            'observateur' => $this->membre($magasin, RoleMagasin::Observateur),
            'expert-comptable' => $this->expertComptable($magasin),
        ];

        foreach ($comptes as $role => $compte) {
            $autorisations = $compte->fresh()->autorisationsDans($magasin);
            $this->assertSame([], array_values(array_intersect($autorisations, self::ARTICLES)), $role);
            $this->assertContains(PermissionRegistry::SERVICE_VIEW, $autorisations, $role);
            $this->assertFalse($compte->fresh()->can(PermissionRegistry::PRODUIT_VIEW), $role);
        }
        $this->assertEqualsCanonicalizing(array_values(array_diff(PermissionRegistry::toutes(), self::ARTICLES, PermissionRegistry::deVente())), $comptes['propriétaire']->autorisationsDans($magasin));
        $this->assertEqualsCanonicalizing(array_values(array_diff(PermissionRegistry::toutes(), self::ARTICLES)), $comptes['responsable']->fresh()->autorisationsDans($magasin));
    }

    /**
     * Les cases que le propriétaire peut cocher, celles que précochent les
     * modèles des rôles et de l'administrateur, et celles que proposent les
     * écrans de l'équipe sont celles du type d'activité de la société.
     */
    public function test_les_cases_proposees_cochables_et_precochees_sont_celles_du_type(): void
    {
        $services = $this->ouvrirUneSociete(['type' => TypeDActivite::Services]);
        $proprietaire = $this->proprietaireDe($services);
        $duType = PermissionRegistry::pourLeType(TypeDActivite::Services);

        $this->assertSame($duType, app(PorteeDesDroits::class)->autorisationsCochables($proprietaire, $services));
        $this->assertSame([], array_values(array_intersect(RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Services), self::ARTICLES)));
        $this->assertSame([], array_values(array_intersect(RoleMagasin::Observateur->permissionsParDefaut(TypeDActivite::Services), self::ARTICLES)));
        $this->assertSame([PermissionRegistry::CLIENT_VIEW, PermissionRegistry::SERVICE_VIEW, PermissionRegistry::DEVIS_VIEW, PermissionRegistry::FACTURE_VIEW], RoleMagasin::Observateur->permissionsParDefaut(TypeDActivite::Services));
        $this->assertSame([], array_values(array_intersect(PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Services), self::ARTICLES)));
        $this->assertSame([], PermissionRegistry::pourLeType(TypeDActivite::Cabinet));

        $this->dansLeMagasin($services, $proprietaire);
        $options = [
            ...EquipeDuMagasinResource::champsAutorisations()[0]->getOptions(),
            ...EquipeDuMagasinResource::champsAutorisations()[1]->getOptions(),
        ];
        $this->assertEqualsCanonicalizing($duType, array_keys($options));

        $this->dansLEspaceDeLaSociete($services->societe, $proprietaire);
        $modele = MembreResource::modeleDeLAdministrateur();
        $this->assertSame([], array_values(array_intersect([...$modele['autorisations_magasin'], ...$modele['autorisations_magasin_vente']], self::ARTICLES)));
    }

    /**
     * Dans une société de services, un changement de rôle vers un membre et
     * une réintégration précochent le modèle du type, et la transmission de
     * la propriété donne à l'ancien propriétaire les seules autorisations du
     * type, sauf celles de la vente.
     */
    public function test_dans_une_societe_de_services_le_changement_de_role_la_reintegration_et_la_transmission_n_ecrivent_que_les_autorisations_du_type(): void
    {
        $magasin = $this->ouvrirUneSociete(['type' => TypeDActivite::Services]);
        $proprietaire = $this->proprietaireDe($magasin);
        $promu = $this->membre($magasin, RoleMagasin::Vendeur);
        app(ChangerLeRoleDeSocieteAction::class)->versAdministrateur($proprietaire, $promu, null, [], []);
        $retire = $this->membre($magasin, RoleMagasin::Vendeur);
        app(ChangerStatutCompteAction::class)->retirer($proprietaire, $retire);

        app(ChangerLeRoleDeSocieteAction::class)->versMembre($proprietaire, $promu, RoleMagasin::Observateur, null, $magasin->id);
        app(ChangerStatutCompteAction::class)->reintegrer($proprietaire, $retire, RoleMagasin::Vendeur);

        $this->assertEqualsCanonicalizing(RoleMagasin::Observateur->permissionsParDefaut(TypeDActivite::Services), $this->casesDe($promu));
        $this->assertEqualsCanonicalizing(RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Services), $this->casesDe($retire));

        $repreneur = $this->administrateurPretAReprendre($magasin);
        $jeton = $this->demanderLaTransmission($proprietaire, $repreneur);
        app(TenantContext::class)->clear();
        app(AccepterLaTransmissionDeLaProprieteAction::class)->handle($repreneur, $jeton, self::MOT_DE_PASSE_CHOISI);

        $this->assertEqualsCanonicalizing(PermissionRegistry::duProprietaire(TypeDActivite::Services), $this->casesDe($proprietaire));
        $this->assertSame([], array_values(array_intersect($this->casesDe($proprietaire), self::ARTICLES)));
    }

    /**
     * @return array<string, array{0: Closure(self, Magasin, User, list<string>): mixed}>
     */
    public static function ecrituresDesCasesDUnAdministrateur(): array
    {
        return [
            'création' => [static fn (self $test, Magasin $magasin, User $proprietaire, array $cases): mixed => app(CreerAdministrateurAction::class)->handle($proprietaire, $magasin->societe, ['name' => 'Alix Martin'], null, $cases, [])],
            'modification' => [static fn (self $test, Magasin $magasin, User $proprietaire, array $cases): mixed => app(ModifierAdministrateurAction::class)->modifier($proprietaire, $test->administrateurDuTest($magasin), null, $cases, [])],
            'changement de rôle' => [static fn (self $test, Magasin $magasin, User $proprietaire, array $cases): mixed => app(ChangerLeRoleDeSocieteAction::class)->versAdministrateur($proprietaire, $test->vendeurDuTest($magasin), null, $cases, [])],
            'réintégration' => [static fn (self $test, Magasin $magasin, User $proprietaire, array $cases): mixed => app(ChangerStatutCompteAction::class)->reintegrerLAdministrateur($proprietaire, $test->administrateurRetireDuTest($magasin), null, $cases, [])],
        ];
    }

    /**
     * Dans une société de services, chacune des écritures des cases d'un
     * administrateur refuse une autorisation de la famille des articles,
     * sans rien écrire, et accepte le modèle de l'administrateur, qui n'en
     * a aucune ; la base n'en garde aucune.
     *
     * @param  Closure(self, Magasin, User, list<string>): mixed  $ecrire
     */
    #[DataProvider('ecrituresDesCasesDUnAdministrateur')]
    public function test_dans_une_societe_de_services_les_cases_d_un_administrateur_ne_prennent_que_les_autorisations_du_type(Closure $ecrire): void
    {
        $magasin = $this->ouvrirUneSociete(['type' => TypeDActivite::Services]);
        $proprietaire = $this->proprietaireDe($magasin);
        $modele = PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Services);

        $this->assertThrows(fn () => $ecrire($this, $magasin, $proprietaire, [...$modele, PermissionRegistry::PRODUIT_VIEW]), InvalidArgumentException::class, 'Autorisation qui n\'existe pas pour une société « Services, sans vente d\'articles » : Produits : voir');
        $ecrire($this, $magasin, $proprietaire, $modele);

        $this->assertSame(0, DB::table('autorisations_affectations')->whereIn('autorisation', self::ARTICLES)->count());
        $this->assertGreaterThan(0, DB::table('autorisations_affectations')->where('autorisation', PermissionRegistry::SERVICE_CREATE)->count());
    }

    /**
     * La base elle-même refuse une case de la famille des articles sur une
     * affectation d'une société sans articles, même écrite sans passer par
     * l'application ; une case de la famille commune passe.
     */
    public function test_la_base_refuse_une_case_d_articles_dans_une_societe_sans_articles(): void
    {
        $magasin = $this->ouvrirUneSociete(['type' => TypeDActivite::Services]);
        $affectation = Affectation::query()->where('user_id', $this->membre($magasin, RoleMagasin::Vendeur)->id)->sole();

        foreach ([PermissionRegistry::PRODUIT_VIEW, PermissionRegistry::FACTURE_JUSTIFIER, PermissionRegistry::STOCK_VENDRE_SANS_STOCK] as $article) {
            $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('autorisations_affectations')->insert(['affectation_id' => $affectation->id, 'autorisation' => $article])), QueryException::class, 'une autorisation de la famille des articles n\'existe pas dans une société sans articles');
        }
        $this->assertThrows($this->dansUnPointDeSauvegarde(fn () => DB::table('autorisations_affectations')->where('affectation_id', $affectation->id)->where('autorisation', PermissionRegistry::FACTURE_VIEW)->update(['autorisation' => PermissionRegistry::PRODUIT_CREATE])), QueryException::class);
        $this->assertThrows(fn () => $affectation->cocher([PermissionRegistry::FACTURE_VIEW, PermissionRegistry::PRODUIT_CREATE]), InvalidArgumentException::class);

        DB::table('autorisations_affectations')->insert(['affectation_id' => $affectation->id, 'autorisation' => PermissionRegistry::AVOIR_VIEW]);

        $this->assertContains(PermissionRegistry::AVOIR_VIEW, $affectation->autorisations());
        $this->assertSame([], array_values(array_intersect($affectation->autorisations(), self::ARTICLES)));
    }

    /**
     * Les services se voient, se créent et se modifient par leurs propres
     * autorisations : celles des produits n'y suffisent pas, et les leurs
     * suffisent sans celles des produits.
     */
    public function test_les_services_se_voient_se_creent_et_se_modifient_par_leurs_propres_autorisations(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $service = Service::factory()->create(['nom' => 'Diagnostic']);
        $deProduits = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::PRODUIT_VIEW, PermissionRegistry::PRODUIT_CREATE, PermissionRegistry::PRODUIT_UPDATE, PermissionRegistry::FACTURE_CREATE]);
        $deServices = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::SERVICE_VIEW, PermissionRegistry::SERVICE_CREATE, PermissionRegistry::SERVICE_UPDATE, PermissionRegistry::FACTURE_CREATE]);

        $this->assertSame([false, false, false, false], [$deProduits->can('viewAny', Service::class), $deProduits->can('view', $service), $deProduits->can('create', Service::class), $deProduits->can('update', $service)]);
        $this->assertSame([true, true, true, true], [$deServices->can('viewAny', Service::class), $deServices->can('view', $service), $deServices->can('create', Service::class), $deServices->can('update', $service)]);
        $this->assertThrows(fn () => app(TrouverOuCreerServiceAction::class)->handle($deProduits, 'Pose de verre trempé'), AuthorizationException::class);
        $this->assertSame('Pose de verre trempé', app(TrouverOuCreerServiceAction::class)->handle($deServices, 'Pose de verre trempé')->nom);
        $this->assertSame(['Services : voir', 'Services : créer', 'Services : modifier', 'Produits : voir'], [
            PermissionRegistry::libelles()[PermissionRegistry::SERVICE_VIEW],
            PermissionRegistry::libelles()[PermissionRegistry::SERVICE_CREATE],
            PermissionRegistry::libelles()[PermissionRegistry::SERVICE_UPDATE],
            PermissionRegistry::libelles()[PermissionRegistry::PRODUIT_VIEW],
        ]);
    }

    /**
     * Le journal des événements (JET 130) d'un magasin d'une société de
     * services ne liste que les autorisations du type : aux droits
     * implicites du propriétaire, inscrits à l'ouverture du magasin de
     * démonstration, puis à la création d'un vendeur d'entraînement.
     */
    public function test_le_jet_130_ne_liste_que_les_autorisations_du_type(): void
    {
        $societe = $this->inscrireUneSociete(type: TypeDActivite::Services);
        $proprietaire = $societe->proprietaire()->firstOrFail();
        app(TenantContext::class)->clear();
        $magasin = app(OuvrirLaDemonstrationAction::class)->handle($proprietaire, $societe);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);

        $modifications = $this->modificationsDesDroits($magasin);
        $duProprietaire = collect($modifications)->where('membre.id', $proprietaire->id)->last();
        $duVendeur = collect($modifications)->where('membre.id', $vendeur->id)->last();

        $this->assertSame(PermissionRegistry::duProprietaire(TypeDActivite::Services), $duProprietaire['apres']['permissions']);
        $this->assertSame([], array_values(array_intersect($duVendeur['apres']['permissions'], self::ARTICLES)));
        $this->assertContains(PermissionRegistry::SERVICE_VIEW, $duVendeur['apres']['permissions']);
    }

    /**
     * Le commerce garde toutes les autorisations d'aujourd'hui : toutes
     * existent dans ses magasins, le propriétaire les a toutes sauf celles
     * de la vente, un responsable toutes, et les modèles gardent celles des
     * articles.
     */
    public function test_le_commerce_garde_toutes_les_autorisations_d_aujourd_hui(): void
    {
        $magasin = $this->ouvrirUneSociete();
        $toutes = PermissionRegistry::toutes();
        sort($toutes);

        $this->assertSame(PermissionRegistry::toutes(), PermissionRegistry::pourLeType(TypeDActivite::Commerce));
        $this->assertEqualsCanonicalizing(array_values(array_diff(PermissionRegistry::toutes(), PermissionRegistry::deVente())), $this->proprietaireDe($magasin)->autorisationsDans($magasin));
        $this->assertSame($toutes, $this->responsable($magasin)->autorisationsDans($magasin));
        $this->assertContains(PermissionRegistry::PRODUIT_VIEW, RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Commerce));
        $this->assertContains(PermissionRegistry::FACTURE_JUSTIFIER, RoleMagasin::Vendeur->permissionsParDefaut(TypeDActivite::Commerce));
        $this->assertEqualsCanonicalizing(array_values(array_diff(PermissionRegistry::toutes(), [PermissionRegistry::PARAMETRES_GERER], PermissionRegistry::deVente())), PermissionRegistry::modeleDeLAdministrateur(TypeDActivite::Commerce));
    }

    /**
     * Un administrateur de tous les magasins de la société de ce magasin,
     * au modèle de l'administrateur.
     */
    public function administrateurDuTest(Magasin $magasin): User
    {
        return $this->administrateur($magasin);
    }

    /**
     * Un vendeur de ce magasin, son mot de passe choisi.
     */
    public function vendeurDuTest(Magasin $magasin): User
    {
        return $this->membre($magasin, RoleMagasin::Vendeur);
    }

    /**
     * Un administrateur de la société de ce magasin, retiré par le
     * propriétaire.
     */
    public function administrateurRetireDuTest(Magasin $magasin): User
    {
        $administrateur = $this->administrateur($magasin);
        app(ChangerStatutCompteAction::class)->retirer($this->proprietaireDe($magasin), $administrateur);

        return $administrateur->fresh();
    }

    /**
     * Les cases de toutes les affectations de ce compte, triées.
     *
     * @return list<string>
     */
    private function casesDe(User $compte): array
    {
        $cases = DB::table('autorisations_affectations')
            ->whereIn('affectation_id', Affectation::query()->where('user_id', $compte->id)->select('id'))
            ->orderBy('autorisation')
            ->pluck('autorisation')
            ->all();

        return array_values(array_unique($cases));
    }
}
