<?php

namespace Tests\Unit;

use App\Domain\TableauDeBord\Support\EcartDesPrixDAchat;
use App\Domain\TableauDeBord\Support\TextesDeLEcartDesPrixDAchat;
use PHPUnit\Framework\TestCase;
use Tests\Fixtures\EcartDesPrixDAchat\LectureDesFixtures;

/**
 * Le volume de la carte « Écart des prix d'achat » (D76, B8), calcul pur :
 * 300 produits de 40 achats chacun, soit 12 000 achats sur les douze mois,
 * sans aucune donnée tirée au hasard. La lecture du mois choisi et celle des
 * douze mois se calculent, et leur taille en JSON est bornée par un nombre
 * écrit ici d'après la mesure du lot (marge de 50 %) : la carte ne limite
 * pas les lignes de ses tableaux, mais n'écrit que l'affichage choisi et le
 * calcul du mois choisi.
 */
class EcartDesPrixDAchatVolumeTest extends TestCase
{
    /**
     * La taille mesurée au lot (277 047 octets) de la lecture par mois du
     * dernier mois, avec 12 000 achats, plus 50 %.
     */
    private const TAILLE_MAXIMALE_DU_MOIS = 415_600;

    /**
     * La taille mesurée au lot (3 605 071 octets) de la lecture des douze
     * mois, avec 12 000 achats (une ligne par achat affiché, six cellules),
     * plus 50 %.
     */
    private const TAILLE_MAXIMALE_DE_LA_PERIODE = 5_407_600;

    /**
     * @return list<array{nom: string, achats: list<array{k: int, j: int, q: int, c: int}>}>
     */
    private static function produits(): array
    {
        $produits = [];

        for ($produit = 0; $produit < 300; $produit++) {
            $achats = [];

            for ($achat = 0; $achat < 40; $achat++) {
                $achats[] = [
                    'k' => intdiv($achat * 12, 40),
                    'j' => 1 + ($produit + $achat * 3) % 28,
                    'q' => 1 + ($produit + $achat) % 20,
                    'c' => 500 + ($produit * 7 + $achat * 13) % 90,
                ];
            }

            $produits[] = ['nom' => sprintf('Produit %03d', $produit), 'achats' => $achats];
        }

        return $produits;
    }

    public function test_le_calcul_de_12_000_achats_donne_des_lectures_de_taille_bornee(): void
    {
        $produits = self::produits();
        $this->assertSame(12000, array_sum(array_map(fn (array $produit): int => count($produit['achats']), $produits)));

        $calcul = EcartDesPrixDAchat::calculer($produits, LectureDesFixtures::moisDeLaMaquette());
        $parMois = TextesDeLEcartDesPrixDAchat::lecture($calcul, TextesDeLEcartDesPrixDAchat::AFFICHAGE_PAR_MOIS, 11);
        $surDouzeMois = TextesDeLEcartDesPrixDAchat::lecture($calcul, TextesDeLEcartDesPrixDAchat::AFFICHAGE_SUR_DOUZE_MOIS, 11);
        $tailleDuMois = strlen(json_encode($parMois, JSON_THROW_ON_ERROR));
        $tailleDeLaPeriode = strlen(json_encode($surDouzeMois, JSON_THROW_ON_ERROR));

        $this->assertGreaterThan(100_000, $tailleDuMois, 'La lecture du mois porte bien le calcul de ses 300 produits.');
        $this->assertLessThanOrEqual(self::TAILLE_MAXIMALE_DU_MOIS, $tailleDuMois);
        $this->assertGreaterThan(1_000_000, $tailleDeLaPeriode, 'La lecture de la période porte bien le calcul de ses achats.');
        $this->assertLessThanOrEqual(self::TAILLE_MAXIMALE_DE_LA_PERIODE, $tailleDeLaPeriode);
    }
}
