<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FacturePdfGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_genere_un_pdf_non_vide_pour_une_facture_emise(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 42, 'taux_tva' => 20]);
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 2],
        ]);
        $facture = app(EmettreFactureAction::class)->handle($facture);

        $pdf = app(FacturePdfGenerator::class)->generer($facture);
        $contenu = $pdf->output();

        $this->assertStringStartsWith('%PDF', $contenu);
        $this->assertGreaterThan(500, strlen($contenu));
    }

    public function test_le_nom_de_fichier_utilise_le_numero_ou_brouillon(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create();
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);

        $generateur = app(FacturePdfGenerator::class);
        $this->assertStringContainsString('brouillon-', $generateur->nomFichier($facture));

        $facture = app(EmettreFactureAction::class)->handle($facture);
        $this->assertSame("facture-{$facture->numero}.pdf", $generateur->nomFichier($facture));
    }

    /**
     * Un client particulier relève de l'e-reporting agrégé, pas d'une
     * facture transmise individuellement (voir
     * Facture::relevantDeLeInvoicing) : le document imprimé porte le
     * libellé usuel "ticket" plutôt que "facture", même s'il s'agit du
     * même enregistrement en base.
     */
    public function test_le_document_est_nomme_ticket_pour_un_client_particulier(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create(); // particulier par défaut
        $produit = Produit::factory()->create();
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $facture = app(EmettreFactureAction::class)->handle($facture);

        $generateur = app(FacturePdfGenerator::class);
        $this->assertSame("ticket-{$facture->numero}.pdf", $generateur->nomFichier($facture));

        // Le binaire PDF compilé n'est pas cherchable en texte brut
        // (flux compressés) : on vérifie le libellé sur le gabarit Blade
        // lui-même plutôt que sur la sortie de dompdf.
        $html = view('factures.pdf', ['facture' => $facture->load('client', 'lignes', 'magasin')])->render();
        $this->assertStringContainsString('Ticket de caisse', $html);
        $this->assertStringNotContainsString('>Facturé à<', $html);
    }

    /**
     * Un montant déjà réglé (encaissement et/ou crédit d'avoir) doit
     * apparaître sur le document remis au client -- sans ça, rien ne
     * prouve sur le PDF qu'une vente entièrement couverte par un avoir a
     * bien été soldée (voir AppliquerAvoirsDisponiblesAction).
     */
    public function test_le_credit_applique_par_un_avoir_apparait_sur_le_pdf(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create();
        $produit = Produit::factory()->create(['prix_vente_ht' => 50, 'taux_tva' => 20, 'suivi_stock' => false]);

        // Premier achat intégralement retourné : 60 € de crédit disponible.
        $premiereFacture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $premiereFacture = app(EmettreFactureAction::class)->handle($premiereFacture);
        $avoir = app(CreerAvoirAction::class)->handle($client, [
            ['ligne_facture_id' => $premiereFacture->lignes->firstOrFail()->id, 'quantite' => 1],
        ]);
        app(EmettreAvoirAction::class)->handle($avoir);

        // Nouvel achat du même montant : couvert automatiquement par le crédit.
        $nouvelleFacture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $nouvelleFacture = app(EmettreFactureAction::class)->handle($nouvelleFacture)->fresh();

        $html = view('factures.pdf', [
            'facture' => $nouvelleFacture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'),
        ])->render();

        $this->assertStringContainsString('Crédité par avoir', $html);
        $this->assertStringContainsString($avoir->fresh()->numero, $html);
        $this->assertStringContainsString('60,00', $html);
        $this->assertStringNotContainsString('Restant dû', $html);
    }

    public function test_aucune_mention_de_reglement_sur_une_facture_non_payee(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create();
        $facture = app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]);
        $facture = app(EmettreFactureAction::class)->handle($facture);

        $html = view('factures.pdf', [
            'facture' => $facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'),
        ])->render();

        $this->assertStringNotContainsString('Payé', $html);
        $this->assertStringNotContainsString('Crédité par avoir', $html);
        $this->assertStringNotContainsString('Restant dû', $html);
    }
}
