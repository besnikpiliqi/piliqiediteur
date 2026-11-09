<?php

namespace Tests\Unit;

use App\Domain\Achats\Services\EcartsDUnTaux;
use PHPUnit\Framework\TestCase;

/**
 * Les écarts d'un taux entre ce qu'écrit la facture du fournisseur et ce
 * que propose PILIQI (voir EcartsDUnTaux) : chaque HT de ligne écarté de
 * plus de 0,02 € et une TVA écartée de plus de 0,01 € par ligne du taux
 * ont chacun leur avertissement, dans l'ordre des lignes, la TVA à la
 * fin ; un écart dans la tolérance n'en a aucun.
 */
class EcartsDUnTauxTest extends TestCase
{
    public function test_chaque_ecart_au_dela_de_la_tolerance_a_son_avertissement(): void
    {
        $ecarts = new EcartsDUnTaux(2000, 1505, 300, 310, [
            ['description' => 'Carnet', 'reference' => 'CARNET', 'ht_calcule' => 500, 'ht_retenu' => 503],
            ['description' => 'Stylo', 'reference' => null, 'ht_calcule' => 500, 'ht_retenu' => 502],
            ['description' => 'Cahier', 'reference' => null, 'ht_calcule' => 500, 'ht_retenu' => 500],
        ]);

        $this->assertSame([
            sprintf(EcartsDUnTaux::AVERTISSEMENT_HT, 'Carnet', '5,03 €', '0,03 €', '5,00 €'),
            sprintf(EcartsDUnTaux::AVERTISSEMENT_TVA, '20 %', '3,10 €', '0,10 €', '3,00 €'),
        ], $ecarts->avertissements());
    }
}
