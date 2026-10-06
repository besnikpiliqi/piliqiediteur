<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\ProduitResource\Pages\ListProduits;
use App\Filament\App\Resources\ProduitResource\Widgets\ValeurStockApercu;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ValeurStockWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_proprietaire_voit_la_valeur_du_stock_et_le_nombre_de_references_epuisees(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        // En stock : 5 unités à 10 € HT, TVA 20 % => 50 € HT, 10 € TVA, 60 € TTC.
        Produit::factory()->create(['prix_achat_ht' => 10, 'taux_tva' => 20, 'stock_quantite' => 5, 'suivi_stock' => true]);
        // Épuisé : une quantité de 0 ne vaut jamais rien (0 × prix = 0), quel
        // que soit son prix d'achat -- seul son NOMBRE doit apparaître.
        Produit::factory()->create(['prix_achat_ht' => 25, 'taux_tva' => 20, 'stock_quantite' => 0, 'suivi_stock' => true]);
        // Non suivi : ne doit jamais compter, quel que soit son stock_quantite résiduel.
        Produit::factory()->create(['prix_achat_ht' => 999, 'taux_tva' => 20, 'stock_quantite' => 3, 'suivi_stock' => false]);

        Livewire::test(ValeurStockApercu::class)
            ->assertSuccessful()
            ->assertSee('60,00 € TTC')
            ->assertSee('Références épuisées')
            ->assertSee('1');
    }

    /**
     * Article par article, comme une ligne de facture : 33,33 € HT donne
     * un PU TTC de 40,00 € ; 3 articles valent donc 99,99 € HT + 20,01 € de
     * TVA = 120,00 € TTC -- jamais 20,00 € de TVA et 119,99 € TTC, ce que
     * donnait une TVA calculée sur le total.
     */
    public function test_la_valeur_du_stock_se_calcule_article_par_article(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Produit::factory()->create(['prix_achat_ht' => 33.33, 'taux_tva' => 20, 'stock_quantite' => 3, 'suivi_stock' => true]);

        Livewire::test(ValeurStockApercu::class)
            ->assertSee('120,00 € TTC')
            ->assertSee('3 unités en stock · 99,99 € HT · 20,01 € TVA')
            ->assertDontSee('119,99');
    }

    public function test_un_vendeur_sans_la_permission_ne_voit_pas_le_widget(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        Permission::findOrCreate(PermissionRegistry::PRODUIT_VIEW, 'web');
        $roleVendeur = Role::findOrCreate('employee', 'web');
        $roleVendeur->givePermissionTo([PermissionRegistry::PRODUIT_VIEW]);

        $vendeur = User::factory()->create();
        $vendeur->assignRole($roleVendeur);
        $magasin->utilisateurs()->attach($vendeur->id, ['role' => 'employee', 'statut' => 'active', 'joined_at' => now()]);

        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        $this->assertFalse(ValeurStockApercu::canView());
    }

    public function test_le_proprietaire_a_bien_la_permission_de_voir_la_valeur_du_stock(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $this->assertTrue(ValeurStockApercu::canView());
    }

    public function test_le_filtre_statut_du_stock_separe_en_stock_et_epuise(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $enStock = Produit::factory()->create(['stock_quantite' => 5, 'suivi_stock' => true]);
        $epuise = Produit::factory()->create(['stock_quantite' => 0, 'suivi_stock' => true]);

        Livewire::test(ListProduits::class)
            ->filterTable('statut_stock', 'en_stock')
            ->assertCanSeeTableRecords([$enStock])
            ->assertCanNotSeeTableRecords([$epuise]);

        Livewire::test(ListProduits::class)
            ->filterTable('statut_stock', 'epuise')
            ->assertCanSeeTableRecords([$epuise])
            ->assertCanNotSeeTableRecords([$enStock]);
    }
}
