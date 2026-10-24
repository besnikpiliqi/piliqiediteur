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
use App\Domain\Tenancy\Enums\RegimeTva;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
     * Même présentation que la facture : mentions du magasin et de la
     * société, client, chaque ligne d'un devis à un professionnel avec son
     * PU HT, son taux et son total HT (point 3), la TVA par taux, les
     * totaux, et les dates d'émission et de validité du devis. 2 écrans à
     * 120,00 € TTC à 20 % : PU HT 100,00 €, 200,00 € HT et 40,00 € de TVA.
     */
    public function test_le_pdf_reprend_les_mentions_les_lignes_la_tva_par_taux_et_la_validite(): void
    {
        $devis = $this->devisEnvoye();

        $html = app(DevisPdfGenerator::class)->html($devis->load('client', 'lignes', 'magasin'));

        foreach ([
            'Boutique du Port', 'Martin et Fils SARL', '12 quai des Pêcheurs', '13002 Marseille', 'SIREN : 121212120', 'SIRET : 12121212000020', 'TVA intracommunautaire : FR56121212120',
            'Établi pour', 'Atelier Martin',
            $devis->numero, 'Émis le 09/10/2026', 'Valable jusqu&#039;au 31/12/2026',
            'Écran 15 pouces', '<td class="montant">2</td>', '<td class="montant">100,00 €</td>', '<td class="montant">20 %</td>',
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
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), $client, [['produit_id' => $produit->id, 'quantite' => 1]]);
        $client->update(['nom' => 'Marc Nouveau', 'siren' => '111222333']);

        $devis = app(EmettreDevisAction::class)->handle(self::auteur(), $devis);

        $this->assertStringContainsString(
            '<div class="bloc-client"><div class="titre">Établi pour</div><strong>Marc Nouveau</strong><br>SIREN : 111222333<br></div>',
            $this->compacte(app(DevisPdfGenerator::class)->html($devis->load('client', 'lignes', 'magasin'))),
        );
    }

    /**
     * Tout devis se calcule comme sa facture, pour un professionnel comme
     * pour un particulier, et porte la même note sous ses lignes (point
     * 15). Si la fiche du client devient professionnelle après son envoi,
     * il se réimprime tel qu'il a été établi : client sans identifiant.
     */
    public function test_tout_devis_explique_le_calcul_de_ses_lignes_et_se_reimprime_tel_qu_etabli(): void
    {
        $note = 'Montants calculés depuis les prix TTC : pour chaque taux, le HT est le TTC du taux ÷ (1 + taux), arrondi une fois au centime, puis réparti sur les lignes ; la TVA est la différence.';
        $devisProfessionnel = $this->devisEnvoye();
        $client = Client::factory()->create(['nom' => 'Jean Ancien']);
        $devisParticulier = app(EmettreDevisAction::class)->handle(self::auteur(), app(CreerDevisAction::class)->handle(self::auteur(), $client, [['produit_id' => Produit::factory()->create()->id, 'quantite' => 1]]));
        $client->update(['type_client' => TypeClient::Professionnel, 'siren' => '111222333']);

        $renduParticulier = $this->compacte(app(DevisPdfGenerator::class)->html($devisParticulier->fresh()->load('client', 'lignes', 'magasin')));

        $this->assertStringContainsString($note, app(DevisPdfGenerator::class)->html($devisProfessionnel->load('client', 'lignes', 'magasin')));
        $this->assertStringContainsString($note, $renduParticulier);
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
        $modifie = app(ModifierDevisAction::class)->handle(self::auteur(), $devis, $client, $lignes, ['date_validite' => '2026-12-31']);

        $html = app(DevisPdfGenerator::class)->html($modifie->load('client', 'lignes', 'magasin'));

        $this->assertStringContainsString("{$devis->numero} (brouillon, modifié depuis son envoi)", $html);
        $this->assertStringNotContainsString('Émis le', $html);
    }

    /**
     * Le PDF d'un devis est, comme celui d'une facture, une vraie page A4
     * en portrait, numérotée (point 4).
     */
    public function test_le_pdf_d_un_devis_est_une_page_a4_numerotee(): void
    {
        $devis = $this->devisEnvoye();

        $pages = $this->pagesDuPdf(app(DevisPdfGenerator::class)->generer($devis)->output());

        $this->assertSame(['0.000 0.000 595.280 841.890'], array_column($pages, 'format'));
        $this->assertContains('Page 1 sur 1', array_column($pages[0]['textes'], 'texte'));
    }

    /**
     * Un devis à un professionnel imprime, comme sa facture, le PU HT et le
     * total HT de chaque ligne ; un devis à un particulier, comme son
     * ticket, le PU TTC et le total TTC (point 3, P3).
     */
    public function test_un_devis_a_un_professionnel_imprime_les_colonnes_ht_et_un_devis_a_un_particulier_les_colonnes_ttc(): void
    {
        $professionnel = $this->devisEnvoye();
        $particulier = app(EmettreDevisAction::class)->handle(self::auteur(), app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->create(), [
            ['produit_id' => Produit::factory()->create(['reference' => 'ECR-15', 'nom' => 'Écran 15 pouces', 'prix_vente_ttc' => 120, 'taux_tva' => 20])->id, 'quantite' => 2],
        ]));

        $tableau = fn (Devis $devis): string => Str::betweenFirst($this->compacte(app(DevisPdfGenerator::class)->html($devis->load('client', 'lignes', 'magasin'))), '<table class="lignes">', '</table>');

        $this->assertStringContainsString('<th class="montant">Qté</th><th class="montant">PU HT</th><th class="montant">Taux</th><th class="montant">Total HT</th>', $tableau($professionnel));
        $this->assertStringContainsString('<td>Écran 15 pouces</td><td class="montant">2</td><td class="montant">100,00 €</td><td class="montant">20 %</td><td class="montant">200,00 €</td>', $tableau($professionnel));
        $this->assertStringContainsString('<th class="montant">Qté</th><th class="montant">PU TTC</th><th class="montant">Taux</th><th class="montant">Total TTC</th>', $tableau($particulier));
        $this->assertStringContainsString('<td class="reference">ECR-15</td><td>Écran 15 pouces</td><td class="montant">2</td><td class="montant">120,00 €</td><td class="montant">20 %</td><td class="montant">240,00 €</td>', $tableau($particulier));
    }

    /**
     * Le pied de page d'un devis porte l'identité de la société, ses
     * conditions de paiement et la franchise de TVA du magasin ; ses notes
     * restent dans le corps du devis (point 4).
     */
    public function test_l_identite_de_la_societe_les_conditions_et_les_mentions_sont_dans_le_pied_de_page(): void
    {
        app(TenantContext::class)->set(Magasin::factory()->avecLaSociete(['raison_sociale' => 'Martin et Fils', 'siren' => '121212120', 'regime_tva' => RegimeTva::FranchiseEnBase])->create());
        $devis = app(EmettreDevisAction::class)->handle(self::auteur(), app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(), [
            ['produit_id' => Produit::factory()->create()->id, 'quantite' => 1],
        ], ['conditions_paiement' => 'Acompte de 30 % à la commande.', 'notes' => 'Livraison sous huit jours.']));

        $html = $this->compacte(app(DevisPdfGenerator::class)->html($devis->load('client', 'lignes', 'magasin')));

        $pied = Str::betweenFirst($html, '<div id="pied-de-page">', '</div></div>');
        $this->assertStringStartsWith('<div class="identite">Martin et Fils ', $pied);
        $this->assertStringContainsString(' · SIREN : 121212120 · ', $pied);
        $this->assertStringEndsWith('</div><div class="pied">Acompte de 30 % à la commande.</div><div class="pied">TVA non applicable, art. 293 B du CGI', $pied);
        $this->assertStringContainsString('<div class="remarque">Livraison sous huit jours.</div>', $html);
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
        $devis = app(CreerDevisAction::class)->handle(self::auteur(), Client::factory()->professionnel()->create(['nom' => 'Atelier Martin']), [
            ['produit_id' => $ecran->id, 'quantite' => 2],
            ['produit_id' => $cable->id, 'quantite' => 1],
        ], ['date_validite' => '2026-12-31']);

        return app(EmettreDevisAction::class)->handle(self::auteur(), $devis);
    }

    /**
     * Le HTML sans les blancs qui entourent ses balises.
     */
    private function compacte(string $html): string
    {
        return preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], $html);
    }
}
