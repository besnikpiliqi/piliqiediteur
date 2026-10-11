<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Models\Facture;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un brouillon n'a jamais reçu de numéro légal (voir EmettreFactureAction) :
 * il n'a aucune existence fiscale, et peut donc être supprimé librement.
 * Voir FactureNeSePasSupprimeTest pour le cas symétrique (facture émise).
 */
class FactureBrouillonSuppressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_createur_peut_supprimer_son_propre_brouillon(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create();
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $this->actingAs($proprietaire);

        $this->assertTrue($proprietaire->can('delete', $facture));
        $this->assertTrue($proprietaire->can('deleteAny', Facture::class));

        $facture->delete();

        $this->assertModelMissing($facture);
    }

    public function test_supprimer_un_brouillon_supprime_aussi_ses_lignes(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = $this->ouvrirUneSociete(['nom' => 'Ma Boutique'], $proprietaire);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create();
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $ligne = $facture->lignes->firstOrFail();

        $this->actingAs($proprietaire);
        $facture->delete();

        $this->assertModelMissing($ligne);
    }
}
