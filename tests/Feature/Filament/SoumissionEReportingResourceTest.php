<?php

namespace Tests\Feature\Filament;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\GenererSoumissionEReportingAction;
use App\Domain\Facturation\Models\SoumissionEReporting;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\SoumissionEReportingResource\Pages\ListSoumissionsEReporting;
use App\Filament\App\Resources\SoumissionEReportingResource\Pages\ViewSoumissionEReporting;
use App\Integrations\Pdp\Contracts\PeriodeEReporting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SoumissionEReportingResourceTest extends TestCase
{
    use RefreshDatabase;

    private function genererUneSoumission(Magasin $magasin): SoumissionEReporting
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        app(EmettreFactureAction::class)->handle($facture);
        $facture->forceFill(['date_emission' => '2026-10-03'])->save();
        app(CloturerCaisseAction::class)->handle($magasin, '2026-10-03');

        $periode = new PeriodeEReporting(
            CarbonImmutable::parse('2026-10-01'),
            CarbonImmutable::parse('2026-10-10'),
            CarbonImmutable::parse('2026-10-20'),
        );

        return app(GenererSoumissionEReportingAction::class)->handle($magasin, $periode);
    }

    public function test_le_proprietaire_voit_la_liste_et_le_detail_dune_soumission(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $soumission = $this->genererUneSoumission($magasin);

        Livewire::test(ListSoumissionsEReporting::class)
            ->assertCanSeeTableRecords([$soumission]);

        Livewire::test(ViewSoumissionEReporting::class, ['record' => $soumission->getRouteKey()])
            ->assertSuccessful()
            ->assertSee($soumission->numero);
    }

    public function test_un_vendeur_sans_vue_totale_ne_voit_pas_la_resource(): void
    {
        $magasin = Magasin::factory()->create();
        app(TenantContext::class)->set($magasin);

        Permission::findOrCreate(PermissionRegistry::CLOTURE_CAISSE_VIEW, 'web');
        $roleVendeur = Role::findOrCreate('employee', 'web');
        $roleVendeur->givePermissionTo(PermissionRegistry::CLOTURE_CAISSE_VIEW);

        $vendeur = User::factory()->create();
        $vendeur->assignRole($roleVendeur);
        $magasin->utilisateurs()->attach($vendeur->id, ['role' => 'employee', 'statut' => 'active', 'joined_at' => now()]);

        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        $this->assertFalse($vendeur->can('viewAny', SoumissionEReporting::class));
    }
}
