<?php

namespace Tests\Feature\Authorization;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
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
     * @return array<string, array{RoleMagasin, bool}>
     */
    public static function roles(): array
    {
        return [
            'administrateur' => [RoleMagasin::Administrateur, true],
            'comptable' => [RoleMagasin::Comptable, false],
            'vendeur' => [RoleMagasin::Vendeur, false],
            'observateur' => [RoleMagasin::Observateur, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_archiver_des_produits_selon_le_role(RoleMagasin $role, bool $peutArchiver): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $membre = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, ['name' => 'Membre', 'email' => 'membre@example.test', 'password' => 'mot-de-passe-sur'], $role);
        $produit = Produit::factory()->create();

        $this->assertSame($peutArchiver, $membre->can('deleteAny', Produit::class));
        $this->assertSame($peutArchiver, $membre->can('delete', $produit));
    }

    /**
     * Restaurer défait l'archivage : les mêmes rôles que pour archiver.
     */
    #[DataProvider('roles')]
    public function test_restaurer_des_produits_selon_le_role(RoleMagasin $role, bool $peutRestaurer): void
    {
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $membre = app(CreerMembreMagasinAction::class)->handle($this->proprietaireDe($magasin), $magasin, ['name' => 'Membre', 'email' => 'membre@example.test', 'password' => 'mot-de-passe-sur'], $role);
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
