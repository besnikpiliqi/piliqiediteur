<?php

namespace Tests\Feature\Authorization;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Actions\CreerMembreMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Supprimer des produits, un par un ou en masse : seulement ceux qui ont
 * la permission de supprimer les produits.
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
    public function test_supprimer_des_produits_selon_le_role(RoleMagasin $role, bool $peutSupprimer): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $membre = app(CreerMembreMagasinAction::class)->handle($magasin, ['name' => 'Membre', 'email' => 'membre@example.test', 'password' => 'mot-de-passe-sur'], $role);
        $produit = Produit::factory()->create();

        $this->assertSame($peutSupprimer, $membre->can('deleteAny', Produit::class));
        $this->assertSame($peutSupprimer, $membre->can('delete', $produit));
    }

    /**
     * Restaurer défait une suppression : les mêmes rôles que pour
     * supprimer.
     */
    #[DataProvider('roles')]
    public function test_restaurer_des_produits_selon_le_role(RoleMagasin $role, bool $peutRestaurer): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $membre = app(CreerMembreMagasinAction::class)->handle($magasin, ['name' => 'Membre', 'email' => 'membre@example.test', 'password' => 'mot-de-passe-sur'], $role);
        $produit = Produit::factory()->create();
        $produit->delete();

        $this->assertSame($peutRestaurer, $membre->can('restore', $produit));
    }

    /**
     * Sa page de modification ne trouve plus un produit supprimé : il se
     * restaure d'abord.
     */
    public function test_un_produit_supprime_ne_se_modifie_pas_avant_sa_restauration(): void
    {
        $proprietaire = User::factory()->create();
        app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $produit = Produit::factory()->create();
        $supprime = Produit::factory()->create();
        $supprime->delete();

        $this->assertTrue($proprietaire->can('update', $produit));
        $this->assertFalse($proprietaire->can('update', $supprime));
    }
}
