<?php

namespace Tests\Feature\Proprietes;

use App\Domain\Facturation\Services\CalculateurLigne;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/**
 * Les calculs des ventes, des retours et des achats tirés au hasard
 * (décision de l'éditeur du 01/11/2026, point 5 bis), sans base : des
 * milliers de documents de 1 à 8 lignes, à tous les taux (dont 8,5 % des
 * départements d'outre-mer et 0 % de la franchise en base), vendus avec la
 * TVA française ou sans elle (export, livraison intracommunautaire), avec
 * ou sans remise, jusqu'à 10 000 articles par ligne. Chaque cas vérifie,
 * contre un calcul refait ici depuis les règles de CLAUDE.md (« Prix et
 * TVA des ventes »), ce qui doit toujours tenir.
 */
class CalculsAuHasardTest extends TestCase
{
    use VerifieDesProprietes;

    private const GRAINE = 20261110;

    private const NOMBRE_DE_CAS = 4000;

    /** Les taux, en centièmes de point : métropole, Corse, outre-mer, franchise. */
    private const TAUX = [2000, 1000, 550, 210, 850, 0];

    private CalculateurLigne $calculateur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculateur = new CalculateurLigne;
    }

    public function test_une_vente_tiree_au_hasard_suit_la_regle_du_ttc_et_du_ht_par_taux(): void
    {
        $this->pourChaqueCas(
            self::GRAINE,
            self::NOMBRE_DE_CAS,
            fn (Tirage $tirage): array => self::lignesDeVente($tirage),
            function (array $lignes): void {
                $calcul = $this->calculateur->chiffrerUneVente($lignes);
                $cumuls = [];

                foreach ($lignes as $rang => $ligne) {
                    $montants = $calcul['lignes'][$rang];
                    $ttcAuPrix = self::ttcDeLaLigne($ligne);
                    $cle = $ligne['taux_tva_du_prix'].'|'.$ligne['taux_tva'];
                    $avant = $cumuls[$cle] ?? ['ttc' => 0, 'ht' => 0];
                    $cumuls[$cle] = ['ttc' => $avant['ttc'] + $ttcAuPrix, 'ht' => self::arrondi(($avant['ttc'] + $ttcAuPrix) * 10000, 10000 + $ligne['taux_tva_du_prix'])];
                    $ht = $cumuls[$cle]['ht'] - $avant['ht'];

                    Assert::assertSame($ht, $montants['montant_ht'], "HT de la ligne {$rang} : celui du cumul de son taux, moins celui des lignes précédentes");
                    Assert::assertSame($montants['montant_ttc'] - $montants['montant_ht'], $montants['montant_tva'], "TVA de la ligne {$rang} : TTC moins HT");
                    Assert::assertGreaterThanOrEqual(0, $montants['montant_tva'], "TVA de la ligne {$rang} jamais négative");
                    Assert::assertSame($ligne['taux_tva'] === $ligne['taux_tva_du_prix'] ? $ttcAuPrix : $ht, $montants['montant_ttc'], "TTC de la ligne {$rang} : quantité x PU TTC, remise déduite, ou son HT sans la TVA française");
                }

                self::verifierLesSommes($calcul);
                self::verifierLaTvaDeChaqueTaux($calcul);
            },
            fn (array $lignes): iterable => self::lignesReduites($lignes),
            isoler: false,
        );
    }

    public function test_les_retours_tires_au_hasard_d_une_ligne_ne_rendent_jamais_plus_qu_elle(): void
    {
        $this->pourChaqueCas(
            self::GRAINE + 1,
            self::NOMBRE_DE_CAS,
            function (Tirage $tirage): array {
                $ligne = self::lignesDeVente($tirage, 1)[0];
                $ligne['quantite'] = $tirage->entier(2, 60);
                $quantites = [];

                for ($rendus = 0; $rendus < $ligne['quantite'];) {
                    $rendus = min($ligne['quantite'], $rendus + $tirage->entier(1, max(1, intdiv($ligne['quantite'], 3))));
                    $quantites[] = $rendus;
                }

                return ['ligne' => $ligne, 'retours' => $quantites];
            },
            function (array $cas): void {
                $ligne = [...$cas['ligne'], ...$this->calculateur->chiffrerUneVente([$cas['ligne']])['lignes'][0]];
                $rendu = ['montant_ht' => 0, 'montant_tva' => 0];

                foreach ($cas['retours'] as $quantite) {
                    $retour = $this->calculateur->retourDeLigne($ligne, $quantite, $rendu);

                    Assert::assertGreaterThanOrEqual(0, $retour['montant_ht'], "HT du retour jusqu'à {$quantite} articles");
                    Assert::assertGreaterThanOrEqual(0, $retour['montant_tva'], "TVA du retour jusqu'à {$quantite} articles");
                    Assert::assertSame($retour['montant_ht'] + $retour['montant_tva'], $retour['montant_ttc']);
                    $rendu = ['montant_ht' => $rendu['montant_ht'] + $retour['montant_ht'], 'montant_tva' => $rendu['montant_tva'] + $retour['montant_tva']];
                    Assert::assertLessThanOrEqual($ligne['montant_ht'], $rendu['montant_ht'], 'Les retours ne rendent jamais plus que le HT de la ligne');
                    Assert::assertLessThanOrEqual($ligne['montant_tva'], $rendu['montant_tva'], 'Les retours ne rendent jamais plus que la TVA de la ligne');
                }

                Assert::assertSame([$ligne['montant_ht'], $ligne['montant_tva']], [$rendu['montant_ht'], $rendu['montant_tva']], 'Le retour de toute la ligne rend exactement ses montants');
            },
            fn (array $cas): iterable => count($cas['retours']) > 1 ? [[...$cas, 'retours' => array_slice($cas['retours'], -1)]] : [],
            isoler: false,
        );
    }

    public function test_un_achat_tire_au_hasard_garde_la_tva_ecrite_et_la_repartit_sans_ligne_negative(): void
    {
        $this->pourChaqueCas(
            self::GRAINE + 2,
            self::NOMBRE_DE_CAS,
            function (Tirage $tirage): array {
                $lignes = array_map(fn (): array => [
                    'prix_unitaire_ht' => $tirage->entier(1, 999_999_999),
                    'quantite' => $tirage->uneFoisSur(3) ? $tirage->entier(2, 1000) : 1,
                    'remise_pourcentage' => $tirage->uneFoisSur(4) ? $tirage->entier(1, 5000) : 0,
                    'taux_tva' => $tirage->parmi([2000, 1000, 550, 210, 0]),
                ], range(1, $tirage->entier(1, 8)));
                $proposees = $this->calculateur->chiffrerUnAchat($lignes)['taux'];
                $ecrites = [];

                foreach ($proposees as $taux => $montants) {
                    if ($taux > 0 && $tirage->uneFoisSur(2)) {
                        $ecrites[$taux] = $tirage->entier(0, $montants['montant_ht']);
                    }
                }

                return ['lignes' => $lignes, 'tva_ecrite' => $ecrites];
            },
            function (array $cas): void {
                $calcul = $this->calculateur->chiffrerUnAchat($cas['lignes'], $cas['tva_ecrite']);
                $parTaux = [];

                foreach ($cas['lignes'] as $rang => $ligne) {
                    $montants = $calcul['lignes'][$rang];
                    Assert::assertGreaterThanOrEqual(0, $montants['montant_tva'], "TVA de la ligne {$rang} jamais négative");
                    Assert::assertLessThanOrEqual($montants['montant_ht'], $montants['montant_tva'], "TVA de la ligne {$rang} jamais plus grande que son HT");
                    $parTaux[$ligne['taux_tva']]['ht'] = ($parTaux[$ligne['taux_tva']]['ht'] ?? 0) + $montants['montant_ht'];
                    $parTaux[$ligne['taux_tva']]['tva'] = ($parTaux[$ligne['taux_tva']]['tva'] ?? 0) + $montants['montant_tva'];
                }

                foreach ($calcul['taux'] as $taux => $montants) {
                    Assert::assertSame($parTaux[$taux]['ht'], $montants['montant_ht'], "Base à {$taux} : la somme de ses lignes");
                    Assert::assertSame($parTaux[$taux]['tva'], $montants['montant_tva'], "TVA à {$taux} : la somme de ses lignes");
                    Assert::assertSame($cas['tva_ecrite'][$taux] ?? self::arrondi($montants['montant_ht'] * $taux, 10000), $montants['montant_tva'], "TVA à {$taux} : celle qu'écrit le fournisseur, sinon base x taux arrondi une fois");
                }

                self::verifierLesSommes($calcul);
            },
            fn (array $cas): iterable => count($cas['lignes']) > 1 ? [['lignes' => array_slice($cas['lignes'], 0, -1), 'tva_ecrite' => []]] : [],
            isoler: false,
        );
    }

    /**
     * De 1 à $nombre lignes de vente : PU TTC jusqu'à 9 999,99 €, quantité
     * jusqu'à 10 000, remise jusqu'à 100 %, le taux que comprend le prix,
     * et une fois sur cinq une vente sans la TVA française (0 % facturé).
     *
     * @return list<array{prix_unitaire_ttc: int, quantite: int, remise_pourcentage: int, taux_tva_du_prix: int, taux_tva: int}>
     */
    private static function lignesDeVente(Tirage $tirage, ?int $nombre = null): array
    {
        $sansTvaFrancaise = $tirage->uneFoisSur(5);

        return array_map(function () use ($tirage, $sansTvaFrancaise): array {
            $tauxDuPrix = $tirage->parmi(self::TAUX);

            return [
                'prix_unitaire_ttc' => $tirage->entier(1, 999_999),
                'quantite' => $tirage->uneFoisSur(3) ? $tirage->entier(2, 10_000) : 1,
                'remise_pourcentage' => $tirage->uneFoisSur(3) ? $tirage->entier(1, 10_000) : 0,
                'taux_tva_du_prix' => $tauxDuPrix,
                'taux_tva' => $sansTvaFrancaise ? 0 : $tauxDuPrix,
            ];
        }, range(1, $nombre ?? $tirage->entier(1, 8)));
    }

    /**
     * Quantité x PU TTC, moins la remise arrondie une fois au centime.
     *
     * @param  array{prix_unitaire_ttc: int, quantite: int, remise_pourcentage: int}  $ligne
     */
    private static function ttcDeLaLigne(array $ligne): int
    {
        $brut = $ligne['prix_unitaire_ttc'] * $ligne['quantite'];

        return $brut - self::arrondi($brut * $ligne['remise_pourcentage'], 10000);
    }

    /**
     * $a ÷ $b au centime le plus proche, un demi-centime exact au centime
     * supérieur ($a positif ou nul, $b positif).
     */
    private static function arrondi(int $a, int $b): int
    {
        return intdiv(2 * $a + $b, 2 * $b);
    }

    /**
     * Chaque total d'un taux et du document est la somme exacte de ses
     * lignes, et chaque TTC son HT plus sa TVA : les totaux que contrôlent
     * les règles de la norme européenne de la facture électronique, au
     * centime, sans tolérance -- le HT du document est la somme de ses
     * lignes (BR-CO-10), sa TVA la somme de ses taux (BR-CO-14), son TTC son
     * HT plus sa TVA (BR-CO-15).
     *
     * @param  array{lignes: list<array{montant_ht: int, montant_tva: int, montant_ttc: int}>, taux: array<int, array{montant_ht: int, montant_tva: int, montant_ttc: int}>, total: array{montant_ht: int, montant_tva: int, montant_ttc: int}}  $calcul
     */
    private static function verifierLesSommes(array $calcul): void
    {
        foreach (['montant_ht', 'montant_tva', 'montant_ttc'] as $montant) {
            Assert::assertSame(array_sum(array_column($calcul['lignes'], $montant)), $calcul['total'][$montant], "{$montant} du document : la somme de ses lignes");
            Assert::assertSame(array_sum(array_column($calcul['taux'], $montant)), $calcul['total'][$montant], "{$montant} du document : la somme de ses taux");
        }

        Assert::assertSame($calcul['total']['montant_ht'] + $calcul['total']['montant_tva'], $calcul['total']['montant_ttc']);
    }

    /**
     * La TVA de chaque taux d'une vente, calculée une fois sur son TTC,
     * s'écarte d'au plus un centime de sa base x taux, arrondie au centime
     * (BR-CO-17, qui tolère 1 €). Celle d'un achat est celle qu'écrit la
     * facture du fournisseur.
     *
     * @param  array{taux: array<int, array{montant_ht: int, montant_tva: int, montant_ttc: int}>}  $calcul
     */
    private static function verifierLaTvaDeChaqueTaux(array $calcul): void
    {
        foreach ($calcul['taux'] as $taux => $montants) {
            Assert::assertLessThanOrEqual(1, abs($montants['montant_tva'] - self::arrondi($montants['montant_ht'] * $taux, 10000)), "BR-CO-17 : la TVA à {$taux} centièmes de point s'écarte de plus d'un centime de sa base x taux");
        }
    }

    /**
     * Les ventes plus petites : sans l'une de ses lignes, puis chaque ligne
     * à un seul article ou sans remise.
     *
     * @param  list<array<string, int>>  $lignes
     * @return iterable<list<array<string, int>>>
     */
    private static function lignesReduites(array $lignes): iterable
    {
        if (count($lignes) > 1) {
            yield from self::etapesReduites($lignes, static fn (): iterable => []);
        }

        foreach ($lignes as $rang => $ligne) {
            if ($ligne['quantite'] > 1 || $ligne['remise_pourcentage'] > 0) {
                $reduites = $lignes;
                $reduites[$rang] = [...$ligne, 'quantite' => 1, 'remise_pourcentage' => 0];

                yield $reduites;
            }
        }
    }
}
