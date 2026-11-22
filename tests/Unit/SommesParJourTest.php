<?php

namespace Tests\Unit;

use App\Domain\TableauDeBord\Support\CalculDesSeries;
use App\Domain\TableauDeBord\Support\HistoriqueDuStock;
use App\Domain\TableauDeBord\Support\SommesParJour;
use PHPUnit\Framework\TestCase;

/**
 * Les montants rangés par jour, que chaque barre d'un graphique par période
 * additionne (lot GRAPH-AN), sans base de données : une somme sur un
 * intervalle de jours compris, les magasins demandés seulement, rien pour un
 * intervalle sans montant ; le grand livre du stock rejoué, jour par jour ;
 * l'écart en % sur un an, dont la base nulle ne se dit pas.
 */
class SommesParJourTest extends TestCase
{
    /**
     * @return list<array{magasin: int, jour: string, montant: int}>
     */
    private static function lignes(): array
    {
        return [
            ['magasin' => 1, 'jour' => '2026-03-10', 'montant' => 1000],
            ['magasin' => 1, 'jour' => '2026-03-10', 'montant' => 250],
            ['magasin' => 1, 'jour' => '2026-03-31', 'montant' => 400],
            ['magasin' => 2, 'jour' => '2026-03-10', 'montant' => 9999],
            ['magasin' => 1, 'jour' => '2026-04-01', 'montant' => -300],
            ['magasin' => 1, 'jour' => '2025-12-31', 'montant' => 70],
        ];
    }

    public function test_la_somme_d_un_intervalle_compte_ses_deux_bornes_et_les_seuls_magasins_demandes(): void
    {
        $sommes = SommesParJour::depuis(self::lignes(), [1], fn (array $ligne): int => $ligne['montant']);

        $this->assertSame(1650, $sommes->entre('2026-03-10', '2026-03-31'), 'Les deux bornes comptent, le magasin 2 jamais.');
        $this->assertSame(1250, $sommes->entre('2026-03-10', '2026-03-10'));
        $this->assertSame(100, $sommes->entre('2026-03-11', '2026-04-30'), 'Un avoir se déduit : 400 − 300, et ce qui précède le 11 mars n\'y est pas.');
        $this->assertSame(1720, $sommes->entre('2025-01-01', '2026-03-31'));
        $this->assertSame(0, $sommes->entre('2026-05-01', '2026-12-31'));
        $this->assertSame(0, $sommes->entre('2026-03-11', '2026-03-30'));
        $this->assertSame(0, $sommes->entre('2026-03-31', '2026-03-10'), 'Un intervalle à l\'envers est vide.');
        $this->assertSame(11249, SommesParJour::depuis(self::lignes(), [1, 2], fn (array $ligne): int => $ligne['montant'])->entre('2026-03-10', '2026-03-10'));
        $this->assertSame(0, SommesParJour::depuis([], [1], fn (array $ligne): int => 1)->entre('2026-01-01', '2026-12-31'));
    }

    public function test_le_grand_livre_rejoue_la_valeur_a_la_fin_de_chaque_jour_et_les_ventes_et_couts_de_chaque_intervalle(): void
    {
        $historique = new HistoriqueDuStock(
            [1 => ['2026-03-12' => 5000, '2026-03-05' => 3000, '2026-04-01' => 0], 2 => ['2026-03-05' => 100]],
            [1 => ['2026-03-12' => 1500, '2026-03-20' => -200]],
            [1 => ['2026-03-12' => 800]],
        );

        $this->assertSame(0, $historique->valeurA([1], '2026-03-04'), 'Avant le premier mouvement : rien.');
        $this->assertSame(3000, $historique->valeurA([1], '2026-03-05'));
        $this->assertSame(3000, $historique->valeurA([1], '2026-03-11'));
        $this->assertSame(5000, $historique->valeurA([1], '2026-03-12'));
        $this->assertSame(5000, $historique->valeurA([1], '2026-03-31'));
        $this->assertSame(0, $historique->valeurA([1], '2026-04-01'), 'Un stock vendu en entier ne vaut rien.');
        $this->assertSame(5100, $historique->valeurA([1, 2], '2026-03-31'));
        $this->assertSame(0, $historique->valeurA([3], '2026-03-31'), 'Un magasin sans grand livre ne vaut rien.');
        $this->assertSame(1300, $historique->ventesEntre([1], '2026-03-01', '2026-03-31'));
        $this->assertSame(1500, $historique->ventesEntre([1], '2026-03-12', '2026-03-19'));
        $this->assertSame(800, $historique->coutsEntre([1], '2026-03-01', '2026-03-31'));
        $this->assertSame(0, $historique->coutsEntre([1, 3], '2026-04-01', '2026-04-30'));
    }

    public function test_l_ecart_sur_un_an_est_sur_la_valeur_absolue_de_l_annee_d_avant_et_ne_se_dit_pas_sans_elle(): void
    {
        $this->assertSame(5000, CalculDesSeries::ecartSurUnAn(6000, 4000), '+50,0 %');
        $this->assertSame(-5000, CalculDesSeries::ecartSurUnAn(2000, 4000));
        $this->assertSame(-10000, CalculDesSeries::ecartSurUnAn(0, 6000), '−100 %');
        $this->assertSame(6667, CalculDesSeries::ecartSurUnAn(10000, 6000), '+66,67 %, arrondi au centième de point');
        $this->assertSame(5000, CalculDesSeries::ecartSurUnAn(-50, -100), 'Un crédit de TVA qui diminue : sur la valeur absolue de l\'année d\'avant.');
        $this->assertSame(0, CalculDesSeries::ecartSurUnAn(4000, 4000));
        $this->assertNull(CalculDesSeries::ecartSurUnAn(4000, 0), 'Rien un an plus tôt : « – », jamais un pourcentage infini.');
        $this->assertSame('+50,0 %', '+'.CalculDesSeries::pourcentage(5000));
    }
}
