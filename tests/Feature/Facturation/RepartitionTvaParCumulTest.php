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
 * R4 (« Prix et TVA des ventes » dans CLAUDE.md) : sur une facture à un
 * professionnel, la TVA de chaque taux se calcule une fois sur le total HT
 * de ses lignes, puis se répartit entre elles par cumul, dans leur ordre :
 * chaque ligne reçoit la TVA arrondie du cumul des HT jusqu'à elle, moins
 * celle des lignes précédentes du même taux (voir
 * CalculateurLigne::repartirLaTva()).
 *
 * La facture de ces tests, au centime :
 * - ligne 1, 1 x 1,00 € TTC à 20 % : 0,83 € HT ; 20 % de 0,83 = 0,166 ->
 *   0,17 € ;
 * - ligne 2, 2 x 4,50 € TTC à 5,5 % : 9,00 ÷ 1,055 = 8,5308 -> 8,53 € HT ;
 *   5,5 % de 8,53 = 0,469 -> 0,47 € ;
 * - ligne 3, 2 x 2,00 € TTC à 20 % : 4,00 ÷ 1,20 = 3,333 -> 3,33 € HT ;
 *   20 % du cumul 4,16 = 0,832 -> 0,83 €, moins les 0,17 € de la ligne 1 :
 *   0,66 €, et non les 0,67 € de 20 % de son seul HT.
 * Totaux : 12,69 € HT, 1,30 € de TVA (0,83 € à 20 %, 0,47 € à 5,5 %),
 * 13,99 € TTC.
 */
class RepartitionTvaParCumulTest extends TestCase
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

    public function test_une_facture_professionnelle_repartit_la_tva_de_chaque_taux_par_cumul_au_centime(): void
    {
        $facture = $this->factureProfessionnelle();

        $this->assertSame([
            ['0.83', '0.17', '1.00'],
            ['8.53', '0.47', '9.00'],
            ['3.33', '0.66', '3.99'],
        ], $this->montantsDesLignes($facture));
        $this->assertSame(['12.69', '1.30', '13.99'], [$facture->total_ht, $facture->total_tva, $facture->total_ttc]);
    }

    public function test_le_contenu_signe_et_le_pdf_portent_les_parts_de_tva_ainsi_reparties(): void
    {
        $facture = $this->factureProfessionnelle();

        $document = EnregistrementFiscal::query()
            ->where('chaine', ChaineFiscale::Vente->value)
            ->where('document_type', $facture->getMorphClass())
            ->where('document_id', $facture->getKey())
            ->sole()
            ->contenuSigne()['donnees']['document'];
        $html = app(FacturePdfGenerator::class)->html($facture->load('client', 'lignes', 'magasin', 'allocationsPaiement', 'allocationsAvoir.avoir'));

        $this->assertSame(
            [['ht' => 83, 'tva' => 17, 'ttc' => 100], ['ht' => 853, 'tva' => 47, 'ttc' => 900], ['ht' => 333, 'tva' => 66, 'ttc' => 399]],
            array_map(static fn (array $ligne): array => Arr::only($ligne, ['ht', 'tva', 'ttc']), $document['lignes']),
        );
        $this->assertSame([
            ['taux' => 2000, 'ht' => 416, 'tva' => 83, 'ttc' => 499],
            ['taux' => 550, 'ht' => 853, 'tva' => 47, 'ttc' => 900],
        ], $document['ventilation']);
        $this->assertSame(['ht' => 1269, 'tva' => 130, 'ttc' => 1399], $document['totaux']);
        $this->assertStringContainsString('<td class="montant">3,33 €</td><td class="montant">0,66 €</td><td class="montant">3,99 €</td>', preg_replace('/>\s+</', '><', $html));
    }

    /**
     * La ligne 3 n'a reçu que 0,66 € de la TVA de son taux. Son premier
     * article rendu crédite 1,67 € HT (2,00 ÷ 1,20) et 0,33 € (20 % de
     * 1,67 = 0,334) ; le second solde la ligne : 3,33 € HT et 0,66 € de
     * TVA, moins le premier retour, soit 1,66 € et 0,33 €. Les deux
     * retours créditent 0,66 € de TVA, jamais les 0,67 € de 20 % du HT de
     * la ligne.
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
        $this->assertSame([['1.66', '0.33', '1.99']], $this->montantsDesLignes($secondRetour));
    }
}
