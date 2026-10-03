<?php

namespace Tests\Feature\Filament;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Filament\App\Resources\AvoirFournisseurResource\Pages\ListAvoirsFournisseur;
use App\Filament\App\Resources\FactureAchatResource\Pages\CreateFactureAchat;
use App\Filament\App\Resources\FactureAchatResource\Pages\ListFacturesAchat;
use App\Filament\App\Resources\FactureResource\Pages\CreateFacture;
use App\Filament\App\Resources\FactureResource\Pages\ListFactures;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AvoirFournisseurResourceTest extends TestCase
{
    use RefreshDatabase;

    private function receptionnerAchatDepuisLePanneau(Fournisseur $fournisseur, Produit $produit, float $quantite): FactureAchat
    {
        Livewire::test(CreateFactureAchat::class)->fillForm([
            'fournisseur_id' => $fournisseur->id,
            'lignes' => [
                ['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => $quantite, 'prix_unitaire_ht' => 20, 'taux_tva' => 20, 'remise_pourcentage' => 0],
            ],
        ])->call('create');

        $factureAchat = FactureAchat::query()->latest('id')->firstOrFail();
        Livewire::test(ListFacturesAchat::class)->callTableAction('valider', $factureAchat);

        return $factureAchat->fresh();
    }

    public function test_laction_avoir_fournisseur_cree_et_emet_un_retour_total(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['prix_achat_ht' => 20, 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->receptionnerAchatDepuisLePanneau($fournisseur, $produit, 5);
        $this->assertSame('5.000', $produit->fresh()->stock_quantite);

        Livewire::test(ListFacturesAchat::class)
            ->callTableAction('avoir_fournisseur', $factureAchat, data: ['motif' => 'Marchandise non conforme']);

        $avoir = AvoirFournisseur::query()->where('facture_achat_id', $factureAchat->id)->firstOrFail();
        $this->assertNotNull($avoir->numero);
        $this->assertSame($factureAchat->total_ttc, $avoir->total_ttc);
        $this->assertSame('0.000', $produit->fresh()->stock_quantite);

        Livewire::test(ListAvoirsFournisseur::class)->assertCanSeeTableRecords([$avoir]);
    }

    /**
     * Exigence explicite du produit : un produit déjà vendu à un client ne
     * doit pas pouvoir être inclus dans l'avoir fournisseur. L'action ne
     * pré-remplit donc que la quantité encore en stock, pas la quantité
     * d'origine de la ligne d'achat.
     */
    public function test_laction_avoir_fournisseur_ne_propose_que_la_quantite_encore_en_stock(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);
        $this->actingAs($proprietaire);
        Filament::setTenant($magasin);

        $client = Client::factory()->create();
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'prix_achat_ht' => 20, 'taux_tva' => 20, 'suivi_stock' => true]);
        $factureAchat = $this->receptionnerAchatDepuisLePanneau($fournisseur, $produit, 5);
        $ligneAchat = $factureAchat->lignes->firstOrFail();

        // 3 unités vendues à un client : il n'en reste que 2 disponibles
        // au retour fournisseur.
        Livewire::test(CreateFacture::class)->fillForm([
            'client_id' => $client->id,
            'lignes' => [['produit_id' => $produit->id, 'description' => $produit->nom, 'quantite' => 3, 'prix_unitaire_ht' => 50, 'taux_tva' => 20, 'remise_pourcentage' => 0]],
        ])->call('create');
        $facture = Facture::query()->where('client_id', $client->id)->latest('id')->firstOrFail();
        Livewire::test(ListFactures::class)->callTableAction('emettre', $facture);
        $this->assertSame(StatutFacture::Emise, $facture->fresh()->statut);

        $this->assertSame('2.000', $produit->fresh()->stock_quantite);

        // Remplissage complet du formulaire de l'action (voir
        // AvoirResourceTest pour l'explication du pourquoi un "data" partiel
        // fusionné via callTableAction duplique l'item par défaut).
        Livewire::test(ListFacturesAchat::class)
            ->mountTableAction('avoir_fournisseur', $factureAchat->fresh())
            ->fillForm([
                'lignes' => [['ligne_achat_id' => $ligneAchat->id, 'quantite' => 2]],
                'motif' => 'Retour du surplus non vendu',
            ], 'mountedTableActionForm')
            ->callMountedTableAction();

        $avoir = AvoirFournisseur::query()->where('facture_achat_id', $factureAchat->id)->firstOrFail();
        $this->assertSame('0.000', $produit->fresh()->stock_quantite);
        $this->assertEquals(0, $ligneAchat->fresh()->quantiteRetournableRestante());
    }
}
