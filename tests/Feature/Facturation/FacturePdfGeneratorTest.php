<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return array<string, array{bool}>
     */
    public static function choixDeLoptionPourLesDebits(): array
    {
        return [
            'option choisie' => [true],
            'option non choisie' => [false],
        ];
    }

    #[DataProvider('choixDeLoptionPourLesDebits')]
    public function test_la_mention_de_loption_pour_les_debits_suit_le_choix_du_magasin(bool $optionDebits): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create(['option_debits_tva' => $optionDebits]));
        $client = Client::factory()->professionnel()->create();
        $produit = Produit::factory()->create();
        $facture = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ]));

        $html = view('factures.pdf', [
            'facture' => $facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'),
        ])->render();

        $this->assertSame($optionDebits, str_contains($html, "Option pour le paiement de la taxe d'après les débits"));
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function naturesDesLignes(): array
    {
        return [
            'un écran seul' => [['bien'], 'Livraison de biens'],
            'une main-d\'œuvre seule' => [['service'], 'Prestation de services'],
            'un écran et sa pose' => [['bien', 'service'], 'Livraison de biens et prestation de services'],
        ];
    }

    /**
     * @param  list<string>  $natures
     */
    #[DataProvider('naturesDesLignes')]
    public function test_la_facture_indique_la_categorie_de_l_operation(array $natures, string $categorie): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->professionnel()->create();
        $facture = app(EmettreFactureAction::class)->handle(app(CreerFactureAction::class)->handle($client, array_map(
            fn (string $nature): array => ['description' => 'Ligne', 'nature' => $nature, 'prix_unitaire_ttc' => 60, 'taux_tva' => 20, 'quantite' => 1],
            $natures,
        )));

        $html = view('factures.pdf', [
            'facture' => $facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'),
        ])->render();

        $this->assertStringContainsString("Catégorie de l'opération : {$categorie}</div>", $html);
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

        // Premier achat payé puis intégralement retourné : 60 € de crédit
        // disponible.
        $premiereFacture = app(EnregistrerVenteCaisseAction::class)->handle($client, [
            ['produit_id' => $produit->id, 'quantite' => 1],
        ], ModePaiement::Especes);
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
