<?php

namespace Tests\Feature\Filament;

use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\FactureAchatResource\Pages\CreateFactureAchat;
use App\Filament\App\Resources\FactureAchatResource\Pages\EditFactureAchat;
use App\Filament\App\Resources\FactureAchatResource\Pages\ListFacturesAchat;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FactureAchatResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_creer_une_facture_dachat_depuis_le_panneau(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['prix_achat_ht' => 20, 'taux_tva' => 20]);

        Livewire::test(CreateFactureAchat::class)
            ->fillForm([
                'fournisseur_id' => $fournisseur->id,
                'numero_fournisseur' => 'INV-2026-42',
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 5, 'prix_unitaire_ht' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $factureAchat = FactureAchat::query()->firstOrFail();
        $this->assertSame(StatutFactureAchat::Brouillon, $factureAchat->statut);
        $this->assertSame('INV-2026-42', $factureAchat->numero_fournisseur);
        $this->assertSame('100.00', $factureAchat->total_ht);
    }

    public function test_valider_une_facture_dachat_depuis_le_panneau_incremente_le_stock(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['prix_achat_ht' => 20, 'taux_tva' => 20, 'stock_quantite' => 0, 'suivi_stock' => true]);

        Livewire::test(CreateFactureAchat::class)->fillForm([
            'fournisseur_id' => $fournisseur->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 5, 'prix_unitaire_ht' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0],
            ],
        ])->call('create');

        $factureAchat = FactureAchat::query()->firstOrFail();
        Livewire::test(ListFacturesAchat::class)->callTableAction('valider', $factureAchat);

        $factureAchat->refresh();
        $this->assertSame(StatutFactureAchat::Validee, $factureAchat->statut);
        $this->assertNotNull($factureAchat->numero);
        $this->assertSame('5.000', $produit->fresh()->stock_quantite);
    }

    public function test_un_brouillon_reste_modifiable_et_supprimable(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['prix_achat_ht' => 20, 'taux_tva' => 20]);

        Livewire::test(CreateFactureAchat::class)->fillForm([
            'fournisseur_id' => $fournisseur->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 5, 'prix_unitaire_ht' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0],
            ],
        ])->call('create');

        $factureAchat = FactureAchat::query()->firstOrFail();

        Livewire::test(ListFacturesAchat::class)->assertTableActionVisible('edit', $factureAchat);
        Livewire::test(ListFacturesAchat::class)->assertTableActionVisible('delete', $factureAchat);

        Livewire::test(EditFactureAchat::class, ['record' => $factureAchat->getRouteKey()])
            ->fillForm([
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 2, 'prix_unitaire_ht' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('40.00', $factureAchat->fresh()->total_ht);
    }

    /**
     * Exigence explicite : une facture d'achat saisie manuellement (donc
     * depuis ce panneau) reste modifiable même une fois validée -- seule
     * sa suppression redevient interdite sans exception.
     */
    public function test_une_facture_dachat_saisie_manuellement_reste_modifiable_apres_validation(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['prix_achat_ht' => 20, 'taux_tva' => 20, 'stock_quantite' => 0, 'suivi_stock' => true]);

        Livewire::test(CreateFactureAchat::class)->fillForm([
            'fournisseur_id' => $fournisseur->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 5, 'prix_unitaire_ht' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0],
            ],
        ])->call('create');

        $factureAchat = FactureAchat::query()->firstOrFail();
        Livewire::test(ListFacturesAchat::class)->callTableAction('valider', $factureAchat);

        Livewire::test(ListFacturesAchat::class)->assertTableActionVisible('edit', $factureAchat->fresh());
        Livewire::test(ListFacturesAchat::class)->assertTableActionHidden('delete', $factureAchat->fresh());

        Livewire::test(EditFactureAchat::class, ['record' => $factureAchat->getRouteKey()])
            ->fillForm([
                'lignes' => [
                    ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 8, 'prix_unitaire_ht' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0],
                ],
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('8.000', $produit->fresh()->stock_quantite, 'La correction doit recalculer le stock déjà incrémenté à la validation.');
    }

    public function test_une_facture_dachat_recuperee_par_api_nest_jamais_modifiable(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        app(TenantContext::class)->set($magasin);
        $factureAchat = FactureAchat::factory()->create(['source' => SourceFactureAchat::Api]);

        Livewire::test(ListFacturesAchat::class)->assertTableActionHidden('edit', $factureAchat);
    }

    /**
     * Exigence explicite : la création rapide d'un produit depuis une
     * ligne de facture d'achat doit proposer les mêmes informations que
     * la page dédiée Produits (prix, TVA, stock...), pas un sous-ensemble
     * réduit.
     */
    public function test_creer_un_nouveau_produit_a_la_volee_depuis_une_ligne_de_facture_dachat(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $fournisseur = Fournisseur::factory()->create();

        Livewire::test(CreateFactureAchat::class)
            ->fillForm([
                'fournisseur_id' => $fournisseur->id,
                'lignes' => [['description' => 'temp']],
            ])
            ->callFormComponentAction('lignes.0.produit_id', 'createOption', data: [
                'reference' => 'ECR-IP17-PRO',
                'nom' => 'Ecran Iphone 17 Pro',
                'prix_achat_ht' => '80',
                'prix_vente_ht' => '150',
                'taux_tva' => '20',
            ])
            ->assertHasNoFormComponentActionErrors();

        $this->assertDatabaseHas('produits', [
            'reference' => 'ECR-IP17-PRO',
            'nom' => 'Ecran Iphone 17 Pro',
            'prix_achat_ht' => 80,
            'prix_vente_ht' => 150,
        ]);
    }
}
