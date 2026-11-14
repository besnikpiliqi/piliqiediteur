<?php

namespace Tests\Feature\Facturation;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;
use App\Domain\CaisseSecurisee\Models\EnregistrementFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerAvoirAction;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreAvoirAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Tests\TestCase;

/**
 * « Prix et TVA des ventes » dans CLAUDE.md (règle (b), décision de
 * l'éditeur du 14/11/2026) : sur une facture, à un professionnel comme à un
 * particulier, la TVA de chaque ligne est son TTC x taux ÷ (1 + taux),
 * arrondie une fois, et son HT le TTC moins cette TVA ; quand la somme des
 * TVA des lignes d'un taux s'écarte de la TVA du TTC du taux, arrondie de
 * même, un centime se corrige sur la ligne dont la TVA arrondie dépasse le
 * plus sa TVA exacte, à égalité celle au plus gros TTC (voir
 * CalculateurLigne::chiffrerUneVente()).
 *
 * La facture de ces tests, au centime :
 * - ligne 1, 1 x 1,00 € TTC à 20 % : TVA 1,00 x 20 ÷ 120 = 0,1666... ->
 *   0,17 €, 0,83 € HT ;
 * - ligne 2, 2 x 4,50 € TTC à 5,5 % : TVA 9,00 x 5,5 ÷ 105,5 = 0,4691... ->
 *   0,47 €, 8,53 € HT ;
 * - ligne 3, 2 x 2,00 € TTC à 20 % : TVA 4,00 x 20 ÷ 120 = 0,6666... ->
 *   0,67 €. Les lignes à 20 % font 0,84 € de TVA, pour une TVA du taux de
 *   5,00 x 20 ÷ 120 = 0,8333... -> 0,83 € : les lignes 1 et 3 dépassent
 *   leur TVA exacte du même tiers de centime, et la ligne 3, au plus gros
 *   TTC, rend le centime : 0,66 € de TVA et 3,34 € HT.
 * Totaux : 12,70 € HT, 1,30 € de TVA (0,83 € à 20 %, 0,47 € à 5,5 %),
 * 14,00 € TTC, la somme des prix.
 */
class TvaLigneParLigneTest extends TestCase
{
    use RefreshDatabase;

    private function factureProfessionnelle(): Facture
    {
        app(TenantContext::class)->set(Magasin::factory()->create(['code' => 'LYON1']));
        $client = Client::factory()->professionnel()->create();
        $article = static fn (string $nom, float $prixTtc, float $taux): Produit => Produit::factory()->create(['nom' => $nom, 'prix_vente_ttc' => $prixTtc, 'taux_tva' => $taux, 'suivi_stock' => false]);
        $vis = $article('Vis', 1.00, 20);
        $farine = $article('Farine', 4.50, 5.5);
        $cheville = $article('Cheville', 2.00, 20);

        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, [
            ['produit_id' => $vis->id, 'quantite' => 1],
            ['produit_id' => $farine->id, 'quantite' => 2],
            ['produit_id' => $cheville->id, 'quantite' => 2],
        ]));
    }

    /**
     * @return list<list<string>> le HT, la TVA et le TTC de chaque ligne, dans leur ordre
     */
    private function montantsDesLignes(Facture|Avoir $document): array
    {
        return $document->lignes()->orderBy('ordre')->get()
            ->map(fn (object $ligne): array => [$ligne->montant_ht, $ligne->montant_tva, $ligne->montant_ttc])
            ->all();
    }

    public function test_une_facture_professionnelle_calcule_la_tva_ligne_par_ligne_et_ajuste_un_centime_par_taux(): void
    {
        $facture = $this->factureProfessionnelle();

        $this->assertSame([
            ['0.83', '0.17', '1.00'],
            ['8.53', '0.47', '9.00'],
            ['3.34', '0.66', '4.00'],
        ], $this->montantsDesLignes($facture));
        $this->assertSame(['12.70', '1.30', '14.00'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
    }

    /**
     * Le contenu signé, en centimes, et le PDF portent ces montants ; le PDF
     * de cette facture à un professionnel (modèle M6-b) imprime le PU HT
     * d'un article de la ligne 3, 2,00 € moins sa TVA de 0,33 €, soit
     * 1,67 €, son PU HT exact, 1,666667 €, en petit sous l'article, et les
     * 3,34 € HT de la ligne, qui a reçu le centime d'ajustement.
     */
    public function test_le_contenu_signe_et_le_pdf_portent_les_montants_de_chaque_ligne(): void
    {
        $facture = $this->factureProfessionnelle();

        $document = EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Vente->value)
            ->where('document_type', $facture->getMorphClass())
            ->where('document_id', $facture->getKey())
            ->sole()
            ->contenuSigne()['donnees']['document'];
        $html = app(FacturePdfGenerator::class)->html($facture->load('client', 'lignes', 'magasin'));

        $this->assertSame(
            [['ht' => 83, 'tva' => 17, 'ttc' => 100], ['ht' => 853, 'tva' => 47, 'ttc' => 900], ['ht' => 334, 'tva' => 66, 'ttc' => 400]],
            array_map(static fn (array $ligne): array => Arr::only($ligne, ['ht', 'tva', 'ttc']), $document['lignes']),
        );
        $this->assertSame([
            ['taux' => 2000, 'ht' => 417, 'tva' => 83, 'ttc' => 500],
            ['taux' => 550, 'ht' => 853, 'tva' => 47, 'ttc' => 900],
        ], $document['ventilation']);
        $this->assertSame(['ht' => 1270, 'tva' => 130, 'ttc' => 1400], $document['totaux']);
        $this->assertStringContainsString('<td>Cheville<span class="sous">PU HT exact : 1,666667 €</span></td><td class="montant">2</td><td class="montant">1,67 €</td><td class="montant">—</td><td class="montant">20 %</td><td class="montant total-de-la-ligne">3,34 €</td>', preg_replace('/>\s+</', '><', $html));
        $this->assertStringContainsString('<td>20 %</td><td class="montant">4,17 €</td><td class="montant">0,83 €</td><td class="montant">5,00 €</td>', preg_replace('/>\s+</', '><', $html));
    }

    /**
     * La ligne 3 n'a que 0,66 € de TVA, après son centime d'ajustement. Son
     * premier article rendu crédite 2,00 € : 0,33 € de TVA (2,00 x 20 ÷ 120
     * = 0,333...) et 1,67 € HT ; le second solde la ligne : 3,34 € HT et
     * 0,66 € de TVA, moins le premier retour, soit 1,67 € et 0,33 €. Les
     * deux retours créditent 0,66 € de TVA, jamais les 0,67 € de ses seuls
     * 4,00 €.
     */
    public function test_le_retour_d_une_ligne_ne_depasse_jamais_sa_part_de_tva(): void
    {
        $facture = $this->factureProfessionnelle();
        $cheville = $facture->lignes()->orderBy('ordre')->get()[2];
        $rendre = fn (LigneFacture $ligne): Avoir => app(EmettreAvoirAction::class)->handle(self::auteur(), app(CreerAvoirAction::class)->handle(self::auteur(), $facture->client, [
            ['ligne_facture_id' => $ligne->id, 'quantite' => 1],
        ]));

        $premierRetour = $rendre($cheville);
        $secondRetour = $rendre($cheville);

        $this->assertSame([['1.67', '0.33', '2.00']], $this->montantsDesLignes($premierRetour));
        $this->assertSame([['1.67', '0.33', '2.00']], $this->montantsDesLignes($secondRetour));
    }
}
