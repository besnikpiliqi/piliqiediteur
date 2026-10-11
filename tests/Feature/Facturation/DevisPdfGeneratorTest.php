<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\ModifierDevisAction;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\LigneDevis;
use App\Domain\Facturation\Services\DevisPdfGenerator;
use App\Domain\Tenancy\Enums\FormeJuridique;
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
     * les totaux, et les dates d'émission et de validité du devis.
     */
    public function test_le_pdf_reprend_les_mentions_les_lignes_la_tva_par_taux_et_la_validite(): void
    {
        $devis = $this->devisEnvoye();

        $html = app(DevisPdfGenerator::class)->html($devis->load('client', 'lignes', 'magasin'));

        foreach ([
            'Boutique du Port', 'Martin et Fils SARL', '12 quai des Pêcheurs', '13002 Marseille', 'SIREN : 121212120', 'SIRET : 12121212000020', 'TVA intracommunautaire : FR56121212120',
            'Établi pour', 'Atelier Martin',
            $devis->numero, 'Émis le 09/10/2026', 'Valable jusqu&#039;au 31/12/2026',
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
     * « Jean Ancien » est devenue « Marc Nouveau », avec un autre SIREN,
     * il imprime la fiche du jour, nom et SIREN compris. Un changement de
     * type, lui, empêche l'envoi tant que le devis n'est pas recalculé
     * (E1, voir DevisConversionTest).
     */
    public function test_le_pdf_imprime_le_nom_et_les_identifiants_de_la_fiche_du_jour(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->create());
        $client = Client::factory()->professionnel()->create(['nom' => 'Jean Ancien', 'siren' => '999888777', 'siret' => null, 'numero_tva' => null]);
        $produit = Produit::factory()->create(['prix_vente_ttc' => 120, 'taux_tva' => 20]);
        $devis = app(CreerDevisAction::class)->handle($client, [['produit_id' => $produit->id, 'quantite' => 1]]);
        $client->update(['nom' => 'Marc Nouveau', 'siren' => '111222333']);

        $devis = app(EmettreDevisAction::class)->handle($devis);

        $this->assertStringContainsString(
            '<div class="bloc-client"><div class="titre">Établi pour</div><strong>Marc Nouveau</strong><br>SIREN : 111222333<br></div>',
            $this->compacte(app(DevisPdfGenerator::class)->html($devis->load('client', 'lignes', 'magasin'))),
        );
    }

    /**
     * Le devis d'un professionnel se calcule comme sa facture (E1) et
     * porte la même note sous ses lignes ; celui établi pour un
     * particulier, article par article, n'en porte pas. Si la fiche du
     * client devient professionnelle après son envoi, il se réimprime
     * comme il a été calculé : client sans identifiant et sans note.
     */
    public function test_le_devis_d_un_professionnel_explique_le_calcul_de_ses_lignes(): void
    {
        $note = 'Client professionnel : HT de chaque ligne = quantité × PU TTC ÷ (1 + taux), arrondi une fois ; TVA calculée sur le total HT de chaque taux.';
        $devisProfessionnel = $this->devisEnvoye();
        $client = Client::factory()->create(['nom' => 'Jean Ancien']);
        $devisParticulier = app(EmettreDevisAction::class)->handle(app(CreerDevisAction::class)->handle($client, [['produit_id' => Produit::factory()->create()->id, 'quantite' => 1]]));
        $client->update(['type_client' => TypeClient::Professionnel, 'siren' => '111222333']);

        $renduParticulier = $this->compacte(app(DevisPdfGenerator::class)->html($devisParticulier->fresh()->load('client', 'lignes', 'magasin')));

        $this->assertStringContainsString($note, app(DevisPdfGenerator::class)->html($devisProfessionnel->load('client', 'lignes', 'magasin')));
        $this->assertStringNotContainsString($note, $renduParticulier);
        $this->assertStringContainsString('<div class="bloc-client"><div class="titre">Établi pour</div><strong>Jean Ancien</strong><br></div>', $renduParticulier);
    }

    /**
     * Modifié depuis son envoi, le devis est un brouillon qui garde son
     * numéro et la date de son envoi : son PDF le dit, sans date
     * d'émission, cette version n'ayant pas encore été émise.
     */
    public function test_le_pdf_d_un_devis_modifie_depuis_son_envoi_le_presente_comme_un_brouillon(): void
    {
        $devis = $this->devisEnvoye();
        $client = $devis->client;
        $lignes = $devis->lignes->map(fn (LigneDevis $ligne): array => ['produit_id' => $ligne->produit_id, 'quantite' => $ligne->quantite])->all();
        $modifie = app(ModifierDevisAction::class)->handle($devis, $client, $lignes, ['date_validite' => '2026-12-31']);

        $html = app(DevisPdfGenerator::class)->html($modifie->load('client', 'lignes', 'magasin'));

        $this->assertStringContainsString("{$devis->numero} (brouillon, modifié depuis son envoi)", $html);
        $this->assertStringNotContainsString('Émis le', $html);
    }

    private function devisEnvoye(): Devis
    {
        $this->travelTo('2026-10-09 10:00:00');
        app(TenantContext::class)->set(Magasin::factory()->avecLaSociete([
            'raison_sociale' => 'Martin et Fils',
            'forme_juridique' => FormeJuridique::Sarl,
            'siren' => '121212120',
            'numero_tva' => 'FR56121212120',
        ])->create([
            'nom' => 'Boutique du Port',
            'adresse' => '12 quai des Pêcheurs',
            'code_postal' => '13002',
            'ville' => 'Marseille',
            'siret' => '12121212000020',
        ]));
        $ecran = Produit::factory()->create(['nom' => 'Écran 15 pouces', 'prix_vente_ttc' => 120, 'taux_tva' => 20]);
        $cable = Produit::factory()->create(['nom' => 'Câble secteur', 'prix_vente_ttc' => 10.55, 'taux_tva' => 5.5]);
        $devis = app(CreerDevisAction::class)->handle(Client::factory()->professionnel()->create(['nom' => 'Atelier Martin']), [
            ['produit_id' => $ecran->id, 'quantite' => 2],
            ['produit_id' => $cable->id, 'quantite' => 1],
        ], ['date_validite' => '2026-12-31']);

        return app(EmettreDevisAction::class)->handle($devis);
    }

    /**
     * Le HTML sans les blancs qui entourent ses balises.
     */
    private function compacte(string $html): string
    {
        return preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], $html);
    }
}
