<?php

namespace Tests\Feature\Achats;

use App\Domain\Achats\Actions\CreerAvoirFournisseurAction;
use App\Domain\Achats\Actions\CreerFactureAchatAction;
use App\Domain\Achats\Actions\EmettreAvoirFournisseurAction;
use App\Domain\Achats\Actions\ModifierFactureAchatAction;
use App\Domain\Achats\Actions\ValiderFactureAchatAction;
use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Facturation\Enums\PrixReference;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

class FactureAchatActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    public function test_creer_une_facture_dachat_calcule_les_lignes_et_les_totaux(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['prix_achat_ht' => 20, 'taux_tva' => 20]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5],
        ]);

        $this->assertSame(StatutFactureAchat::Brouillon, $factureAchat->statut);
        $this->assertNull($factureAchat->numero);
        $this->assertSame('100.00', $factureAchat->total_ht);
        $this->assertSame('120.00', $factureAchat->total_ttc);
        $this->assertSame('0.000', $produit->fresh()->stock_quantite, 'Un brouillon ne doit avoir aucun impact sur le stock.');
    }

    /**
     * Un fournisseur facture le plus souvent en HT, mais un ticket de
     * magasin se saisit en TTC : chaque ligne est calculée depuis le prix
     * réellement saisi (8,33 € HT x 3 = 24,99 € HT ; 12 € TTC x 3 = 36,00 €
     * TTC), jamais en reconvertissant l'un dans l'autre avant de multiplier.
     */
    public function test_chaque_ligne_dachat_est_calculee_depuis_le_prix_reellement_saisi(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['prix_achat_ht' => 5, 'taux_tva' => 20]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 3, 'prix_unitaire_ht' => 8.33],
            ['produit_id' => $produit->id, 'quantite' => 3, 'prix_unitaire_ttc' => 12],
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        [$ligneHt, $ligneTtc, $ligneCatalogue] = $factureAchat->lignes->sortBy('ordre')->values()->all();

        $this->assertSame(PrixReference::Ht, $ligneHt->prix_reference);
        $this->assertSame(['24.99', '5.00', '29.99'], [$ligneHt->montant_ht, $ligneHt->montant_tva, $ligneHt->montant_ttc]);

        $this->assertSame(PrixReference::Ttc, $ligneTtc->prix_reference);
        $this->assertSame(['30.00', '6.00', '36.00'], [$ligneTtc->montant_ht, $ligneTtc->montant_tva, $ligneTtc->montant_ttc]);
        $this->assertSame('10.00', $ligneTtc->prix_unitaire_ht);

        $this->assertSame(PrixReference::Ht, $ligneCatalogue->prix_reference);
        $this->assertSame('5.00', $ligneCatalogue->prix_unitaire_ht);
        $this->assertSame('6.00', $ligneCatalogue->prix_unitaire_ttc);
    }

    public function test_une_ligne_sans_produit_est_refusee(): void
    {
        $fournisseur = Fournisseur::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['quantite' => 5],
        ]);
    }

    public function test_valider_une_facture_dachat_lui_attribue_un_numero_et_incremente_le_stock(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['prix_achat_ht' => 20, 'taux_tva' => 20, 'stock_quantite' => 3, 'suivi_stock' => true]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5],
        ]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle($factureAchat);

        $this->assertSame(StatutFactureAchat::Validee, $factureAchat->statut);
        $this->assertNotNull($factureAchat->numero);
        $this->assertNotNull($factureAchat->date_validation);
        $this->assertSame('8.000', $produit->fresh()->stock_quantite);
    }

    /**
     * Le seul champ de coût suivi par cette application est prix_achat_ht
     * (pas de coût moyen pondéré par lot) : la validation d'une réception
     * doit le rafraîchir au dernier prix payé.
     */
    public function test_valider_une_facture_dachat_met_a_jour_le_prix_dachat_du_produit(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['prix_achat_ht' => 20, 'taux_tva' => 20]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 25],
        ]);
        app(ValiderFactureAchatAction::class)->handle($factureAchat);

        $this->assertSame('25.00', $produit->fresh()->prix_achat_ht);
    }

    public function test_impossible_de_valider_deux_fois_la_meme_facture_dachat(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5],
        ]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle($factureAchat);

        $this->expectException(LogicException::class);
        app(ValiderFactureAchatAction::class)->handle($factureAchat->fresh());
    }

    public function test_un_brouillon_reste_modifiable_lignes_comprises(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produitInitial = Produit::factory()->create(['prix_achat_ht' => 20, 'taux_tva' => 20]);
        $nouveauProduit = Produit::factory()->create(['prix_achat_ht' => 10, 'taux_tva' => 20]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produitInitial->id, 'quantite' => 5],
        ]);

        $factureAchat = app(ModifierFactureAchatAction::class)->handle($factureAchat, [
            ['produit_id' => $nouveauProduit->id, 'quantite' => 2],
        ]);

        $this->assertSame(1, $factureAchat->lignes()->count());
        $this->assertSame($nouveauProduit->id, $factureAchat->lignes->first()->produit_id);
        $this->assertSame('20.00', $factureAchat->total_ht);
    }

    /**
     * Exigence explicite : une facture d'achat saisie manuellement reste
     * modifiable même après validation (la personne qui l'enregistre peut
     * se tromper), et la modification recalcule le stock et le prix
     * d'achat du produit plutôt que de réappliquer la réception en aveugle.
     */
    public function test_une_facture_dachat_validee_saisie_manuellement_reste_modifiable_et_recalcule_le_stock(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['prix_achat_ht' => 20, 'taux_tva' => 20, 'stock_quantite' => 3, 'suivi_stock' => true]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5, 'prix_unitaire_ht' => 20],
        ]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle($factureAchat);

        $this->assertSame(SourceFactureAchat::Manuelle, $factureAchat->source);
        $this->assertTrue($factureAchat->estModifiable());
        $this->assertSame('8.000', $produit->fresh()->stock_quantite);

        // Correction d'une erreur de saisie : 5 reçus en réalité, pas 8.
        $factureAchat = app(ModifierFactureAchatAction::class)->handle($factureAchat, [
            ['produit_id' => $produit->id, 'quantite' => 8, 'prix_unitaire_ht' => 22],
        ]);

        $this->assertSame('8.000', $factureAchat->lignes->first()->quantite);
        $this->assertSame('11.000', $produit->fresh()->stock_quantite, '3 (stock avant réception) + 8 (quantité corrigée) = 11.');
        $this->assertSame('22.00', $produit->fresh()->prix_achat_ht);
    }

    public function test_une_facture_dachat_recuperee_par_api_nest_jamais_modifiable(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['suivi_stock' => false]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5],
        ], ['source' => SourceFactureAchat::Api]);

        $this->assertFalse($factureAchat->estModifiable(), 'Même en brouillon, une facture API ne doit jamais être modifiable.');

        $this->expectException(LogicException::class);
        app(ModifierFactureAchatAction::class)->handle($factureAchat, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
    }

    public function test_une_facture_dachat_deja_retournee_au_fournisseur_nest_plus_modifiable(): void
    {
        $fournisseur = Fournisseur::factory()->create();
        $produit = Produit::factory()->create(['prix_achat_ht' => 20, 'taux_tva' => 20, 'stock_quantite' => 0, 'suivi_stock' => true]);

        $factureAchat = app(CreerFactureAchatAction::class)->handle($fournisseur, [
            ['produit_id' => $produit->id, 'quantite' => 5],
        ]);
        $factureAchat = app(ValiderFactureAchatAction::class)->handle($factureAchat);
        $ligneAchat = $factureAchat->lignes->firstOrFail();

        $avoir = app(CreerAvoirFournisseurAction::class)->handle($factureAchat, [
            ['ligne_achat_id' => $ligneAchat->id, 'quantite' => 2],
        ], 'Produit défectueux');
        app(EmettreAvoirFournisseurAction::class)->handle($avoir);

        $this->assertFalse($factureAchat->fresh()->estModifiable());

        $this->expectException(LogicException::class);
        app(ModifierFactureAchatAction::class)->handle($factureAchat->fresh(), [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
    }
}
