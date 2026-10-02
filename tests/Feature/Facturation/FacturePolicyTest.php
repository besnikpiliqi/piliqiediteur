<?php

namespace Tests\Feature\Facturation;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Confidentialité par vendeur, décision produit : sans
 * FACTURE_VIEW_TOUTES, un vendeur ne voit que les factures qu'il a
 * lui-même créées (voir app/Policies/FacturePolicy.php).
 */
class FacturePolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_un_vendeur_sans_vue_totale_ne_voit_que_ses_propres_factures(): void
    {
        $magasin = Magasin::factory()->create();
        $contexte = app(TenantContext::class);
        $contexte->set($magasin);

        Permission::findOrCreate(PermissionRegistry::FACTURE_VIEW, 'web');
        $roleVendeur = Role::findOrCreate('employee', 'web');
        $roleVendeur->givePermissionTo(PermissionRegistry::FACTURE_VIEW);

        $vendeurA = User::factory()->create();
        $vendeurB = User::factory()->create();
        $vendeurA->assignRole($roleVendeur);
        $vendeurB->assignRole($roleVendeur);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 10, 'taux_tva' => 20]);

        $this->actingAs($vendeurA);
        $contexte->set($magasin);
        $factureDeA = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $this->actingAs($vendeurB);
        $contexte->set($magasin);
        $factureDeB = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $this->assertTrue($vendeurB->can('view', $factureDeB));
        $this->assertFalse($vendeurB->can('view', $factureDeA), "Un vendeur ne doit pas voir les factures d'un collègue.");

        $this->actingAs($vendeurA);
        $contexte->set($magasin);
        $this->assertTrue($vendeurA->can('view', $factureDeA));
        $this->assertFalse($vendeurA->can('view', $factureDeB));
    }

    public function test_avec_la_permission_vue_totale_un_role_voit_toutes_les_factures(): void
    {
        $magasin = Magasin::factory()->create();
        $contexte = app(TenantContext::class);
        $contexte->set($magasin);

        Permission::findOrCreate(PermissionRegistry::FACTURE_VIEW, 'web');
        Permission::findOrCreate(PermissionRegistry::FACTURE_VIEW_TOUTES, 'web');
        $roleComptable = Role::findOrCreate('accountant', 'web');
        $roleComptable->givePermissionTo([PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES]);

        $vendeur = User::factory()->create();
        $comptable = User::factory()->create();
        $roleVendeur = Role::findOrCreate('employee', 'web');
        Permission::findOrCreate(PermissionRegistry::FACTURE_VIEW, 'web');
        $roleVendeur->givePermissionTo(PermissionRegistry::FACTURE_VIEW);
        $vendeur->assignRole($roleVendeur);
        $comptable->assignRole($roleComptable);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 10, 'taux_tva' => 20]);

        $this->actingAs($vendeur);
        $contexte->set($magasin);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $this->actingAs($comptable);
        $contexte->set($magasin);
        $this->assertTrue($comptable->can('view', $facture));
    }
}
