<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exigence légale explicite : une facture ne doit jamais pouvoir être
 * supprimée, brouillon y compris, quel que soit le rôle de l'utilisateur
 * (y compris le propriétaire). Toute correction passe par une annulation
 * (statut) ou, plus tard, un avoir -- jamais par une suppression.
 */
class FactureNeSePasSupprimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_meme_le_proprietaire_ne_peut_pas_supprimer_une_facture(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create();
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $this->actingAs($proprietaire);

        $this->assertFalse($proprietaire->can('delete', $facture));
        $this->assertFalse($proprietaire->can('forceDelete', $facture));
        $this->assertFalse($proprietaire->can('deleteAny', Facture::class));
    }

    public function test_le_modele_facture_ne_possede_aucune_capacite_de_suppression_douce(): void
    {
        $this->assertFalse(method_exists(Facture::class, 'trashed'));
    }
}
