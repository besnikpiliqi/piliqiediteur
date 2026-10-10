<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\MagasinUser;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le seul rappel exécuté avant les policies (voir ControleDesDroits) : les
 * permissions du membre dans le magasin actif, et, dans un magasin
 * suspendu ou fermé, le refus de tout ce qui n'y reste pas permis.
 */
class ControleDesDroitsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Chaque autorisation du propriétaire, et si elle lui reste dans un
     * magasin en lecture seule : consulter, clôturer la journée et gérer
     * l'équipe seulement.
     *
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function autorisationsEnLectureSeule(): array
    {
        $permises = [
            PermissionRegistry::CLIENT_VIEW,
            PermissionRegistry::FOURNISSEUR_VIEW,
            PermissionRegistry::PRODUIT_VIEW,
            PermissionRegistry::PRODUIT_VOIR_VALEUR_STOCK,
            PermissionRegistry::DEVIS_VIEW,
            PermissionRegistry::FACTURE_VIEW,
            PermissionRegistry::FACTURE_VIEW_TOUTES,
            PermissionRegistry::EQUIPE_VIEW,
            PermissionRegistry::EQUIPE_CREER,
            PermissionRegistry::EQUIPE_MODIFIER,
            PermissionRegistry::PAIEMENT_VIEW,
            PermissionRegistry::AVOIR_VIEW,
            PermissionRegistry::CLOTURE_CAISSE_VIEW,
            PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES,
            PermissionRegistry::CLOTURE_CAISSE_GERER,
            PermissionRegistry::FACTURE_ACHAT_VIEW,
            PermissionRegistry::AVOIR_FOURNISSEUR_VIEW,
            PermissionRegistry::CAISSE_SECURISEE_CONSULTER,
        ];
        $refusees = [
            PermissionRegistry::CLIENT_CREATE,
            PermissionRegistry::CLIENT_UPDATE,
            PermissionRegistry::CLIENT_ARCHIVER,
            PermissionRegistry::FOURNISSEUR_CREATE,
            PermissionRegistry::FOURNISSEUR_UPDATE,
            PermissionRegistry::FOURNISSEUR_ARCHIVER,
            PermissionRegistry::PRODUIT_CREATE,
            PermissionRegistry::PRODUIT_UPDATE,
            PermissionRegistry::PRODUIT_ARCHIVER,
            PermissionRegistry::PRODUIT_AJUSTER_STOCK,
            PermissionRegistry::PRIX_VENTE_REGLER,
            PermissionRegistry::DEVIS_CREATE,
            PermissionRegistry::DEVIS_UPDATE,
            PermissionRegistry::DEVIS_ENVOYER,
            PermissionRegistry::FACTURE_CREATE,
            PermissionRegistry::FACTURE_EMETTRE,
            PermissionRegistry::FACTURE_ENVOYER,
            PermissionRegistry::RELANCES_REGLER,
            PermissionRegistry::PARAMETRES_GERER,
            PermissionRegistry::PAIEMENT_CREER,
            PermissionRegistry::PAIEMENT_CONTRE_PASSER,
            PermissionRegistry::AVOIR_CREER,
            PermissionRegistry::AVOIR_ENVOYER,
            PermissionRegistry::FACTURE_ACHAT_CREER,
            PermissionRegistry::FACTURE_ACHAT_VALIDER,
            PermissionRegistry::AVOIR_FOURNISSEUR_CREER,
        ];

        return [
            ...array_combine($permises, array_map(static fn (string $permission): array => [$permission, true], $permises)),
            ...array_combine($refusees, array_map(static fn (string $permission): array => [$permission, false], $refusees)),
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function autorisations(): array
    {
        return array_map(static fn (array $regle): array => [$regle[0]], self::autorisationsEnLectureSeule());
    }

    /**
     * La liste ci-dessus couvre toutes les autorisations : une nouvelle
     * autorisation se range parmi les permises ou les refusées.
     */
    public function test_chaque_autorisation_a_sa_regle_en_lecture_seule(): void
    {
        $couvertes = array_keys(self::autorisationsEnLectureSeule());

        $this->assertEqualsCanonicalizing(PermissionRegistry::toutes(), $couvertes);
    }

    #[DataProvider('autorisations')]
    public function test_le_proprietaire_d_un_magasin_actif_garde_chaque_autorisation(string $permission): void
    {
        $proprietaire = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $this->assertTrue($proprietaire->can($permission));
    }

    #[DataProvider('autorisationsEnLectureSeule')]
    public function test_le_proprietaire_d_un_magasin_suspendu_ne_garde_que_les_autorisations_permises(string $permission, bool $permise): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->passerEn($magasin, StatutMagasin::Suspendu);

        $this->assertSame($permise, $proprietaire->can($permission));
    }

    /**
     * Permise ne veut pas dire accordée : un vendeur sans accès au registre
     * fiscal n'y accède pas plus en lecture seule.
     */
    public function test_une_autorisation_permise_en_lecture_seule_reste_a_accorder(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $this->passerEn($magasin, StatutMagasin::Ferme);

        $this->assertFalse($vendeur->can(PermissionRegistry::CAISSE_SECURISEE_CONSULTER));
    }

    public function test_en_lecture_seule_consulter_cloturer_et_gerer_l_equipe_restent_permis(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur);
        $saLigne = $magasin->membres()->where('user_id', $vendeur->id)->sole();
        $brouillon = app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => Produit::factory()->create(['suivi_stock' => false])->id, 'quantite' => 1]]);
        $this->passerEn($magasin, StatutMagasin::Suspendu);

        $this->assertTrue($proprietaire->can('viewAny', Facture::class));
        $this->assertTrue($proprietaire->can('view', $brouillon));
        $this->assertTrue($proprietaire->can('cloturer', ClotureCaisse::class));
        $this->assertTrue($proprietaire->can('fermer', ClotureCaissePersonnelle::class));
        $this->assertTrue($proprietaire->can('create', MagasinUser::class));
        $this->assertTrue($proprietaire->can('update', $saLigne));
        $this->assertTrue($proprietaire->can('delete', $saLigne));
    }

    public function test_en_lecture_seule_tout_autre_geste_est_refuse(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $produit = Produit::factory()->create(['suivi_stock' => false]);
        $brouillon = app(CreerFactureAction::class)->handle(Client::factory()->create(), [['produit_id' => $produit->id, 'quantite' => 1]]);
        $this->passerEn($magasin, StatutMagasin::Ferme);

        $this->assertFalse($proprietaire->can('create', Facture::class));
        $this->assertFalse($proprietaire->can('update', $brouillon));
        $this->assertFalse($proprietaire->can('delete', $brouillon));
        $this->assertFalse($proprietaire->can('emettre', $brouillon));
        $this->assertFalse($proprietaire->can('update', $produit));
    }

    /**
     * Le magasin actif (voir TenantContext) est bien celui dont le statut
     * change : les droits vérifiés ensuite lisent son nouveau statut.
     */
    private function passerEn(Magasin $magasin, StatutMagasin $statut): void
    {
        $magasin->forceFill(['statut' => $statut])->save();
        $this->assertSame($magasin, app(TenantContext::class)->magasin());
    }
}
