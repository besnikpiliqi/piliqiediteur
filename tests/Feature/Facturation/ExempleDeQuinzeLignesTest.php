<?php

namespace Tests\Feature\Facturation;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\CreerFactureAction;
use App\Domain\Facturation\Actions\EmettreFactureAction;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\FacturePdfGenerator;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * L'exemple de quinze lignes montré à l'éditeur (exemple-calcul-des-prix-15-lignes-regle-b2.pdf,
 * modèles M6-b et P1) : la règle (b) qu'il a choisie pour toutes les ventes
 * (décision de l'éditeur du 14/11/2026, puce « Prix et TVA des ventes » de
 * CLAUDE.md) -- le TTC de chaque ligne depuis son PU TTC, la TVA de chaque
 * ligne, son TTC x taux ÷ (1 + taux), arrondie une fois, son HT le TTC
 * moins cette TVA, un centime ajusté par taux quand la somme des TVA des
 * lignes s'écarte de celle du TTC du taux -- et le PU HT imprimé sur une
 * facture à un professionnel, celui d'un article, son PU TTC moins sa TVA
 * arrondie au centime, avec en petit sous l'article son PU HT exact. Les
 * montants attendus ont été recalculés à la main en fractions exactes : à
 * 20 %, les TVA arrondies des lignes font 487,36 € pour une TVA du taux de
 * 2 924,12 x 20 ÷ 120 = 487,353... -> 487,35 € ; le savon (1,665 -> 1,67 €)
 * et les stylos (4,125 -> 4,13 €) dépassent le plus leur TVA exacte, d'un
 * demi-centime chacun, et les stylos, au plus gros TTC, rendent le
 * centime (4,12 € de TVA, 20,63 € HT) ; à 10 % et à 5,5 %, les TVA des
 * lignes font déjà celle du taux (10,86 € et 2,50 €). La remise de la
 * ligne 13 tombe sur un demi-centime exact (7,495 -> 7,50 €).
 */
class ExempleDeQuinzeLignesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les quinze lignes, dans leur ordre : référence, quantité, PU TTC,
     * remise et taux, puis le PU HT enregistré, le PU HT d'un article, tel
     * que la facture à un professionnel l'imprime (9,99 € TTC à 20 % :
     * 9,99 - 1,67 = 8,32 €), et le HT, la TVA et le TTC de la ligne selon la
     * règle (b) (100 bougies : 1 000,00 x 20 ÷ 120 = 166,666... -> 166,67 €
     * de TVA, 833,33 € HT ; le parfum : 260,00 € -> 43,33 €, 216,67 € HT ;
     * le sac : 142,40 € -> 23,733... -> 23,73 €, 118,67 € HT ; le vélo :
     * 1 249,00 € -> 208,166... -> 208,17 €, 1 040,83 € HT). La ligne 7 vend,
     * remisé, un autre produit au prix de la ligne 1 : un produit n'a
     * qu'une ligne (décision de l'éditeur du 26/10/2026, série 9).
     *
     * @var list<array{0: string, 1: int, 2: string, 3: int, 4: string, 5: string, 6: string, 7: string, 8: string, 9: string}>
     */
    private const LIGNES = [
        ['CRM-1000', 2, '10.00', 0, '20', '8.333333', '8,33 €', '16.67', '3.33', '20.00'],
        ['SAV-0999', 1, '9.99', 0, '20', '8.325000', '8,32 €', '8.32', '1.67', '9.99'],
        ['SHP-1266', 3, '12.66', 0, '20', '10.550000', '10,55 €', '31.65', '6.33', '37.98'],
        ['BOU-1000', 100, '10.00', 0, '20', '8.333333', '8,33 €', '833.33', '166.67', '1000.00'],
        ['LIV-1590', 2, '15.90', 0, '5.5', '15.071090', '15,07 €', '30.14', '1.66', '31.80'],
        ['PLT-1850', 4, '18.50', 0, '10', '16.818182', '16,82 €', '67.27', '6.73', '74.00'],
        ['CRM-1001', 2, '10.00', 10, '20', '8.333333', '8,33 €', '15.00', '3.00', '18.00'],
        ['MO-HEURE', 3, '54.00', 0, '20', '45.000000', '45,00 €', '135.00', '27.00', '162.00'],
        ['PRF-6500', 4, '65.00', 0, '20', '54.166667', '54,17 €', '216.67', '43.33', '260.00'],
        ['JUS-0135', 12, '1.35', 0, '5.5', '1.279621', '1,28 €', '15.36', '0.84', '16.20'],
        ['SAL-0330', 7, '3.30', 0, '10', '3.000000', '3,00 €', '21.00', '2.10', '23.10'],
        ['STY-0099', 25, '0.99', 0, '20', '0.825000', '0,82 €', '20.63', '4.12', '24.75'],
        ['SAC-14990', 1, '149.90', 5, '20', '124.916667', '124,92 €', '118.67', '23.73', '142.40'],
        ['VEL-124900', 1, '1249.00', 0, '20', '1040.833333', '1 040,83 €', '1040.83', '208.17', '1249.00'],
        ['SDW-0745', 3, '7.45', 0, '10', '6.772727', '6,77 €', '20.32', '2.03', '22.35'],
    ];

    /**
     * Base HT, TVA et TTC de chaque taux, du plus fort au plus faible, puis
     * ceux du document.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string}>
     */
    private const TAUX = [
        ['20 %', '2 436,77 €', '487,35 €', '2 924,12 €'],
        ['10 %', '108,59 €', '10,86 €', '119,45 €'],
        ['5,5 %', '45,50 €', '2,50 €', '48,00 €'],
    ];

    private const TOTAUX = ['2 590,86 €', '500,71 €', '3 091,57 €'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::aParis('2026-11-10 10:00:00'));
        app(TenantContext::class)->set(Magasin::factory()->create());
    }

    /**
     * Le calcul lui-même (voir CalculateurLigne::chiffrerUneVente()), en
     * centimes : chaque ligne, chaque taux et le document ont les montants
     * vérifiés, et chaque total est la somme exacte de ses lignes.
     */
    public function test_le_calcul_des_quinze_lignes_donne_les_montants_verifies(): void
    {
        $calculateur = app(CalculateurLigne::class);
        $centimes = fn (string $montant): int => $calculateur->enCentiemes($montant);

        $calcul = $calculateur->chiffrerUneVente(array_map(fn (array $ligne): array => [
            'prix_unitaire_ttc' => $centimes($ligne[2]),
            'quantite' => $ligne[1],
            'remise_pourcentage' => $ligne[3] * 100,
            'taux_tva_du_prix' => $centimes($ligne[4]),
            'taux_tva' => $centimes($ligne[4]),
        ], self::LIGNES));

        $this->assertSame(
            array_map(fn (array $ligne): array => ['montant_ht' => $centimes($ligne[7]), 'montant_tva' => $centimes($ligne[8]), 'montant_ttc' => $centimes($ligne[9])], self::LIGNES),
            $calcul['lignes'],
        );
        $this->assertSame([
            2000 => ['montant_ht' => 243677, 'montant_tva' => 48735, 'montant_ttc' => 292412],
            550 => ['montant_ht' => 4550, 'montant_tva' => 250, 'montant_ttc' => 4800],
            1000 => ['montant_ht' => 10859, 'montant_tva' => 1086, 'montant_ttc' => 11945],
        ], $calcul['taux']);
        $this->assertSame(['montant_ht' => 259086, 'montant_tva' => 50071, 'montant_ttc' => 309157], $calcul['total']);
        $this->assertSame(
            array_map(fn (array $ligne): int => $calculateur->enMillioniemes($ligne[5]), self::LIGNES),
            array_map(fn (array $ligne): int => $calculateur->prixUnitaireHtExact($centimes($ligne[2]), $centimes($ligne[4])), self::LIGNES),
        );
    }

    /**
     * La facture à un professionnel émise enregistre ces montants, et son
     * PDF les imprime au modèle M6-b : pour chaque ligne la référence,
     * l'article avec en petit son PU HT exact, tel qu'il est enregistré
     * (« PU HT exact : 8,333333 € »), la quantité, le PU HT d'un article, son
     * PU TTC moins sa TVA arrondie au centime (8,33 €, 8,32 €, 10,55 €,
     * 15,07 €, 45,00 €...), la remise (« — » sans remise), le taux et le
     * total HT ; puis la base HT, la TVA et le TTC de chaque taux, et les
     * totaux du document. Le PDF lui-même, lu page par page, porte chaque
     * PU HT et chaque total.
     */
    public function test_la_facture_a_un_professionnel_imprime_le_pu_ht_d_un_article_et_les_montants_verifies(): void
    {
        $facture = $this->emettre(Client::factory()->professionnel()->create());

        $this->assertMontantsEnregistres($facture);
        $this->assertSame(array_column(self::LIGNES, 5), $facture->lignes->sortBy('ordre')->pluck('prix_unitaire_ht')->all());

        $html = $this->compacte(app(FacturePdfGenerator::class)->html($facture->load('client', 'lignes', 'magasin')));
        $tableau = Str::betweenFirst($html, '<table class="lignes">', '</table>');

        foreach (self::LIGNES as $rang => [$reference, $quantite, , $remise, $taux, $puHtExact, $puHt, $ht]) {
            $this->assertStringContainsString(
                "<td class=\"reference\">{$reference}</td><td>Article {$reference}<span class=\"sous\">PU HT exact : ".self::auMillionieme($puHtExact)."</span></td><td class=\"montant\">{$quantite}</td><td class=\"montant\">{$puHt}</td><td class=\"montant\">".self::remise($remise).'</td><td class="montant">'.self::taux($taux).'</td><td class="montant total-de-la-ligne">'.$this->euros($ht).'</td></tr>',
                $tableau,
                'Ligne '.($rang + 1),
            );
        }

        $this->assertTauxEtTotauxImprimes($html, 'Net à payer');

        $textes = $this->textesDuPdf($facture);
        foreach ([...array_column(self::LIGNES, 6), ...self::TOTAUX] as $texte) {
            $this->assertContains($texte, $textes);
        }
    }

    /**
     * Le même panier en ticket à un particulier a les mêmes montants, au
     * centime, et son PDF, au modèle P1, imprime pour chaque ligne le PU TTC
     * et le total TTC, sans PU HT ni PU HT exact, puis les mêmes taux et
     * les mêmes totaux.
     */
    public function test_le_meme_panier_en_ticket_a_les_memes_montants_et_imprime_les_pu_ttc(): void
    {
        $ticket = $this->emettre(Client::factory()->create());

        $this->assertMontantsEnregistres($ticket);

        $html = $this->compacte(app(FacturePdfGenerator::class)->html($ticket->load('client', 'lignes', 'magasin')));
        $tableau = Str::betweenFirst($html, '<table class="lignes">', '</table>');

        foreach (self::LIGNES as $rang => [$reference, $quantite, $puTtc, $remise, $taux, , , , , $ttc]) {
            $this->assertStringContainsString(
                "<td class=\"reference\">{$reference}</td><td>Article {$reference}</td><td class=\"montant\">{$quantite}</td><td class=\"montant\">".$this->euros($puTtc).'</td><td class="montant">'.self::remise($remise).'</td><td class="montant">'.self::taux($taux).'</td><td class="montant total-de-la-ligne">'.$this->euros($ttc).'</td></tr>',
                $tableau,
                'Ligne '.($rang + 1),
            );
        }

        $this->assertStringNotContainsString('PU HT', $tableau);
        $this->assertTauxEtTotauxImprimes($html, 'Net à payer');
    }

    /**
     * Les quinze lignes, vendues à ce client, émises.
     */
    private function emettre(Client $client): Facture
    {
        $produits = [];

        foreach (self::LIGNES as [$reference, , $prixTtc, , $taux]) {
            $produits[$reference] ??= Produit::factory()->create(['reference' => $reference, 'nom' => "Article {$reference}", 'prix_vente_ttc' => $prixTtc, 'taux_tva' => $taux, 'suivi_stock' => false]);
        }

        return app(EmettreFactureAction::class)->handle(self::auteur(), app(CreerFactureAction::class)->handle(self::auteur(), $client, array_map(fn (array $ligne): array => [
            'produit_id' => $produits[$ligne[0]]->getKey(),
            'quantite' => $ligne[1],
            'remise_pourcentage' => $ligne[3],
        ], self::LIGNES)));
    }

    /**
     * Le HT, la TVA et le TTC de chaque ligne et du document, tels
     * qu'enregistrés.
     */
    private function assertMontantsEnregistres(Facture $vente): void
    {
        $this->assertSame(
            array_map(fn (array $ligne): array => [$ligne[7], $ligne[8], $ligne[9]], self::LIGNES),
            $vente->lignes->sortBy('ordre')->map(fn (LigneFacture $ligne): array => [$ligne->montant_ht, $ligne->montant_tva, $ligne->montant_ttc])->values()->all(),
        );
        $this->assertSame(['2590.86', '500.71', '3091.57'], [$vente->total_ht, $vente->total_tva, $vente->total_ttc]);
    }

    /**
     * Le tableau des taux et les totaux du PDF (voir documents/pdf/totaux).
     */
    private function assertTauxEtTotauxImprimes(string $html, string $libelleDuTotal): void
    {
        foreach (self::TAUX as [$taux, $base, $tva, $ttc]) {
            $this->assertStringContainsString("<tr><td>{$taux}</td><td class=\"montant\">{$base}</td><td class=\"montant\">{$tva}</td><td class=\"montant\">{$ttc}</td></tr>", $html);
        }

        foreach (['Total HT', 'Total TVA', $libelleDuTotal] as $rang => $libelle) {
            $this->assertMatchesRegularExpression('#<td class="libelle">'.$libelle.'</td><td class="montant">'.preg_quote(self::TOTAUX[$rang], '#').'</td>#', $html);
        }
    }

    /**
     * Les textes du PDF généré, toutes pages réunies.
     *
     * @return list<string>
     */
    private function textesDuPdf(Facture $facture): array
    {
        return array_merge(...array_map(
            fn (array $page): array => array_column($page['textes'], 'texte'),
            $this->pagesDuPdf(app(FacturePdfGenerator::class)->generer($facture)->output()),
        ));
    }

    /**
     * Un montant écrit comme sa colonne, tel que le PDF l'imprime.
     */
    private function euros(string $montant): string
    {
        $calculateur = app(CalculateurLigne::class);

        return $calculateur->enEuros($calculateur->enCentiemes($montant));
    }

    private static function taux(string $taux): string
    {
        return str_replace('.', ',', $taux).' %';
    }

    /**
     * La remise telle que la colonne l'imprime : « — » sans remise.
     */
    private static function remise(int $remise): string
    {
        return $remise === 0 ? '—' : "{$remise} %";
    }

    /**
     * Un PU HT enregistré, écrit avec ses six décimales comme un montant :
     * « 8,333333 € », « 1 040,833333 € ».
     */
    private static function auMillionieme(string $prix): string
    {
        [$euros, $decimales] = explode('.', $prix);

        return preg_replace('/\B(?=(\d{3})+$)/', ' ', $euros).','.$decimales.' €';
    }

    /**
     * Le HTML sans les blancs qui entourent ses balises.
     */
    private function compacte(string $html): string
    {
        return preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], $html);
    }
}
