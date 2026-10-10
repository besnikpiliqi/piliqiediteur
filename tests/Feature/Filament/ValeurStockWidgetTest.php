<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Filament\App\Resources\ProduitResource\Pages\ListProduits;
use App\Filament\App\Resources\ProduitResource\Widgets\ValeurStockApercu;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
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
        Produit::factory()->create(['cmup_ht' => 10, 'taux_tva' => 20, 'stock_quantite' => 5, 'suivi_stock' => true]);
        // Épuisé : une quantité de 0 ne vaut jamais rien (0 × prix = 0), quel
        // que soit son prix d'achat -- seul son NOMBRE doit apparaître.
        Produit::factory()->create(['cmup_ht' => 25, 'taux_tva' => 20, 'stock_quantite' => 0, 'suivi_stock' => true]);
        // Non suivi : ne doit jamais compter, quel que soit son stock_quantite résiduel.
        Produit::factory()->create(['cmup_ht' => 999, 'taux_tva' => 20, 'stock_quantite' => 3, 'suivi_stock' => false]);

        Livewire::test(ValeurStockApercu::class)
            ->assertSuccessful()
            ->assertSee('60,00 € TTC')
            ->assertSee('Références épuisées')
            ->assertSee('1');
    }

    /**
     * La valeur d'un stock est sa quantité x son CMUP au dix-millième,
     * arrondie une seule fois : 900 protections d'écran au CMUP de
     * 0,045 € valent 40,50 € HT (+ 8,10 € de TVA = 48,60 € TTC), pas
     * 900 x 0,05 € = 45,00 € HT.
     */
    public function test_la_valeur_du_stock_est_sa_quantite_fois_son_cmup(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        Produit::factory()->create(['cmup_ht' => 0.045, 'taux_tva' => 20, 'stock_quantite' => 900, 'suivi_stock' => true]);

        Livewire::test(ValeurStockApercu::class)
            ->assertSee('48,60 € TTC')
            ->assertSee('900 unités en stock · 40,50 € HT · 8,10 € TVA');
    }

    /**
     * Un produit archivé n'est plus proposé, mais ce qu'il en reste en
     * stock existe toujours : il compte dans la valeur du stock. Épuisé,
     * il n'est pas une référence à réapprovisionner.
     */
    public function test_le_stock_d_un_produit_archive_compte_dans_la_valeur_mais_pas_dans_les_references_epuisees(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);
        Produit::factory()->create(['nom' => 'A', 'cmup_ht' => 7, 'taux_tva' => 20, 'stock_quantite' => 0, 'suivi_stock' => true]);
        Produit::factory()->trashed()->create(['nom' => 'B', 'cmup_ht' => 4, 'taux_tva' => 20, 'stock_quantite' => 5, 'suivi_stock' => true]);
        Produit::factory()->trashed()->create(['nom' => 'C', 'cmup_ht' => 9, 'taux_tva' => 20, 'stock_quantite' => 0, 'suivi_stock' => true]);

        $widget = Livewire::test(ValeurStockApercu::class)->instance();
        $tuiles = collect((fn (): array => $this->getStats())->call($widget))
            ->mapWithKeys(fn (Stat $tuile): array => [$tuile->getLabel() => [$tuile->getValue(), $tuile->getDescription()]]);

        $this->assertSame([
            'Valeur du stock' => ['24,00 € TTC', '5 unités en stock · 20,00 € HT · 4,00 € TVA'],
            'Références épuisées' => ['1', 'À réapprovisionner'],
        ], $tuiles->all());
    }

    public function test_un_vendeur_sans_la_permission_ne_voit_pas_le_widget(): void
    {
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], User::factory()->create());
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::PRODUIT_VIEW]);

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
