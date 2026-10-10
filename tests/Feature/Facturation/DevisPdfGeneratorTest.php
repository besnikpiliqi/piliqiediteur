<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Services\DevisPdfGenerator;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevisPdfGeneratorTest extends TestCase
{
    use RefreshDatabase;

    public function test_genere_un_pdf_nomme_d_apres_le_numero_du_devis(): void
    {
        $devis = $this->devisEnvoye();
        $generateur = app(DevisPdfGenerator::class);

        $this->assertStringStartsWith('%PDF', $generateur->generer($devis)->output());
        $this->assertSame("devis-{$devis->numero}.pdf", $generateur->nomFichier($devis));
    }

    /**
     * Même présentation que la facture : mentions du magasin, client,
     * chaque ligne avec son PU HT, son PU TTC et sa TVA, la TVA par taux,
     * les totaux, et la date de validité du devis.
     */
    public function test_le_pdf_reprend_les_mentions_les_lignes_la_tva_par_taux_et_la_validite(): void
    {
        $devis = $this->devisEnvoye();

        $html = view('devis.pdf', ['devis' => $devis->load('client', 'lignes', 'magasin')])->render();

        foreach ([
            'Boutique du Port', 'Martin et Fils SARL', '12 quai des Pêcheurs', '13002 Marseille', 'SIREN : 123456789', 'SIRET : 12345678900011', 'TVA intracommunautaire : FR12123456789',
            'Établi pour', 'Atelier Martin',
            $devis->numero, 'Valable jusqu&#039;au 31/12/2026',
            'Écran 15 pouces', '<td class="montant">2</td>', '<td class="montant">100,00 €</td>', '<td class="montant">120,00 €</td>', '<td class="montant">20 %</td>',
            'Câble secteur', '<td class="montant">5,5 %</td>',
            'Base HT', '<td class="montant">200,00 €</td>', '<td class="montant">40,00 €</td>',
            'Total TTC', '« Bon pour accord »',
        ] as $attendu) {
            $this->assertStringContainsString($attendu, $html);
        }
    }

    /**
     * Un devis ne fige pas son client : envoyé après que la fiche de
     * « Jean Ancien », particulier, est devenue « Marc Nouveau »,
     * professionnel, il imprime la fiche du jour, nom et SIREN compris.
     */
    public function test_le_pdf_imprime_le_nom_et_les_identifiants_de_la_fiche_du_jour(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->create(['nom' => 'Jean Ancien']);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20]);
        $devis = app(CreerDevisAction::class)->handle($client, [['produit_id' => $produit->id, 'quantite' => 1]]);
        $client->update(['nom' => 'Marc Nouveau', 'type_client' => TypeClient::Professionnel, 'siren' => '111222333']);

        $devis = app(EmettreDevisAction::class)->handle($devis);

        $this->assertStringContainsString(
            '<div class="bloc-client"><div class="titre">Établi pour</div><strong>Marc Nouveau</strong><br>SIREN : 111222333<br></div>',
            preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], view('devis.pdf', ['devis' => $devis->load('client', 'lignes', 'magasin')])->render()),
        );
    }

    private function devisEnvoye(): Devis
    {
        app(TenantContext::class)->set(Magasin::factory()->create([
            'nom' => 'Boutique du Port',
            'raison_sociale' => 'Martin et Fils',
            'forme_juridique' => 'SARL',
            'adresse' => '12 quai des Pêcheurs',
            'code_postal' => '13002',
            'ville' => 'Marseille',
            'siren' => '123456789',
            'siret' => '12345678900011',
            'numero_tva' => 'FR12123456789',
        ]));
        $ecran = Produit::factory()->create(['nom' => 'Écran 15 pouces', 'prix_vente_ttc' => 120, 'taux_tva' => 20]);
        $cable = Produit::factory()->create(['nom' => 'Câble secteur', 'prix_vente_ttc' => 10.55, 'taux_tva' => 5.5]);
        $devis = app(CreerDevisAction::class)->handle(Client::factory()->professionnel()->create(['nom' => 'Atelier Martin']), [
            ['produit_id' => $ecran->id, 'quantite' => 2],
            ['produit_id' => $cable->id, 'quantite' => 1],
        ], ['date_validite' => '2026-12-31']);

        return app(EmettreDevisAction::class)->handle($devis);
    }
}
