<?php

namespace Tests\Feature\Authorization;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Archiver des produits, un par un ou en masse, et les restaurer :
 * seulement ceux qui ont la permission d'archiver les produits.
 */
class ProduitPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{RoleMagasin, list<string>|null, bool}>
     */
    public static function roles(): array
    {
        return [
            'vendeur aux cases de l\'administrateur' => [RoleMagasin::Vendeur, PermissionRegistry::modeleDeLAdministrateur(), true],
            'vendeur aux cases de l\'ancien comptable' => [RoleMagasin::Vendeur, self::casesDeLAncienComptable(), false],
            'vendeur' => [RoleMagasin::Vendeur, null, false],
            'observateur' => [RoleMagasin::Observateur, null, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_archiver_des_produits_selon_le_role(RoleMagasin $role, ?array $autorisations, bool $peutArchiver): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $membre = $this->membre($magasin, $role, $autorisations, ['name' => 'Membre', 'email' => 'membre@example.test']);
        $produit = Produit::factory()->create();

        $this->assertSame($peutArchiver, $membre->can('deleteAny', Produit::class));
        $this->assertSame($peutArchiver, $membre->can('delete', $produit));
    }

    /**
     * Restaurer défait l'archivage : les mêmes rôles que pour archiver.
     */
    #[DataProvider('roles')]
    public function test_restaurer_des_produits_selon_le_role(RoleMagasin $role, ?array $autorisations, bool $peutRestaurer): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $membre = $this->membre($magasin, $role, $autorisations, ['name' => 'Membre', 'email' => 'membre@example.test']);
        $produit = Produit::factory()->trashed()->create();

        $this->assertSame($peutRestaurer, $membre->can('restore', $produit));
    }

    /**
     * Sa page de modification ne trouve plus un produit archivé : il se
     * restaure d'abord. Aucune fiche ne s'efface, même pour le
     * propriétaire.
     */
    public function test_un_produit_archive_ne_se_modifie_pas_avant_sa_restauration_et_ne_s_efface_jamais(): void
    {
        $proprietaire = User::factory()->create();
        $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $produit = Produit::factory()->create();
        $archive = Produit::factory()->trashed()->create();

        $this->assertTrue($proprietaire->can('update', $produit));
        $this->assertFalse($proprietaire->can('update', $archive));
        $this->assertFalse($proprietaire->can('forceDelete', $archive));
    }
}
