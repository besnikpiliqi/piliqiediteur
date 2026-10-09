<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerAvoirAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tenancy\Actions\CreerMagasinAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exigence légale explicite : une facture ÉMISE (numérotée) ne doit
 * jamais pouvoir être supprimée, quel que soit le rôle de l'utilisateur
 * (y compris le propriétaire). Toute correction passe par un avoir --
 * jamais par une suppression.
 *
 * Un brouillon, lui, n'a reçu aucun numéro légal (voir
 * EmettreFactureAction) : il n'a aucune existence fiscale et peut donc
 * être supprimé librement, voir FactureBrouillonSuppressionTest.
 */
class FactureNeSePasSupprimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_meme_le_proprietaire_ne_peut_pas_supprimer_une_facture_emise(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create();
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $facture = app(EmettreFactureAction::class)->handle($facture);

        $this->actingAs($proprietaire);

        $this->assertFalse($proprietaire->can('delete', $facture));
        $this->assertFalse($proprietaire->can('forceDelete', $facture));
    }

    public function test_une_facture_corrigee_par_un_avoir_total_reste_definitivement_non_supprimable(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create();
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $facture = app(EmettreFactureAction::class)->handle($facture);
        app(EnregistrerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $facture->lignes->firstOrFail()->id, 'quantite' => 1],
        ], 'Erreur de saisie');

        $this->actingAs($proprietaire);

        $this->assertFalse($proprietaire->can('delete', $facture->fresh()));
        $this->assertFalse($proprietaire->can('update', $facture->fresh()));
    }

    public function test_le_modele_facture_ne_possede_aucune_capacite_de_suppression_douce(): void
    {
        $this->assertFalse(method_exists(Facture::class, 'trashed'));
    }

    /**
     * Un brouillon n'a aucune existence légale (voir delete() ci-dessus) :
     * il reste librement modifiable, lignes comprises (voir
     * ModifierFactureAction et FactureResourceTest), pour éviter d'obliger
     * l'utilisateur à tout ressaisir pour corriger une erreur de saisie.
     * Dès l'émission, la modification redevient interdite sans exception :
     * une facture numérotée se corrige par un avoir, jamais en la
     * réécrivant.
     */
    public function test_seul_le_brouillon_est_modifiable_pas_une_facture_emise(): void
    {
        $proprietaire = User::factory()->create();
        $magasin = app(CreerMagasinAction::class)->handle(['nom' => 'Ma Boutique'], $proprietaire);

        $client = Client::factory()->create();
        $produit = Produit::factory()->create();
        $brouillon = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $emise = app(EmettreFactureAction::class)->handle(
            app(CreerFactureAction::class)->handle($client, [
                ['produit_id' => $produit->id, 'quantite' => 1],
            ])
        );

        $this->actingAs($proprietaire);

        $this->assertTrue($proprietaire->can('update', $brouillon));
        $this->assertFalse($proprietaire->can('update', $emise));
    }
}
