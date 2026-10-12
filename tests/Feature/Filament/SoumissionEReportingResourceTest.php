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
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Resources\SoumissionEReportingResource\Pages\ListSoumissionsEReporting;
use App\Filament\App\Resources\SoumissionEReportingResource\Pages\ViewSoumissionEReporting;
use App\Integrations\Pdp\Contracts\PeriodeEReporting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SoumissionEReportingResourceTest extends TestCase
{
    use RefreshDatabase;

    private function genererUneSoumission(Magasin $magasin): SoumissionEReporting
    {
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 100, 'taux_tva' => 20, 'suivi_stock' => false]);
        $facture = app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        // La vente s'émet le jour clôturé : elle se date à l'instant du
        // registre fiscal (voir EmettreFactureAction).
        $this->travelTo('2026-10-03 10:00:00');
        app(EmettreFactureAction::class)->handle(self::auteur(), $facture);
        app(CloturerCaisseAction::class)->handle(self::auteur(), $magasin, '2026-10-03');
        $this->travelBack();

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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
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
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique']);
        $vendeur = $this->membre($magasin, RoleMagasin::Vendeur, [PermissionRegistry::CLOTURE_CAISSE_VIEW]);

        $this->actingAs($vendeur);
        Filament::setTenant($magasin);

        $this->assertFalse($vendeur->can('viewAny', SoumissionEReporting::class));
    }

    public function test_la_soumission_ventile_les_ventes_de_ses_clotures_par_taux_de_tva(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $soumission = $this->genererUneSoumission($magasin);

        $this->assertSame([
            ['taux_tva' => '20.00', 'montant_ht' => 100.0, 'montant_tva' => 20.0, 'montant_ttc' => 120.0],
        ], $soumission->ventilation_tva);

        Livewire::test(ViewSoumissionEReporting::class, ['record' => $soumission->getRouteKey()])
            ->assertSee('TVA par taux')
            ->assertSee('20 %');
    }
}
