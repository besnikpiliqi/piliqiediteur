<?php

namespace Tests\Unit;

use App\Domain\Facturation\Casts\QuantiteArticles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuantiteArticlesTest extends TestCase
{
    /**
     * Entière, une quantité se lit en entier ; une ancienne quantité à
     * virgule se relit exactement, jamais tronquée.
     *
     * @return array<string, array{mixed, int|float}>
     */
    public static function quantitesEnregistrees(): array
    {
        return [
            'décimal à zéro' => ['2.000', 2],
            'entier' => [7, 7],
            'flottant entier' => [3.0, 3],
            'ancienne quantité à virgule' => ['1.500', 1.5],
            'au millième' => ['0.125', 0.125],
            'somme flottante' => [0.1 + 0.2, 0.3],
            'stock négatif' => ['-2.000', -2],
        ];
    }

    #[DataProvider('quantitesEnregistrees')]
    public function test_une_quantite_se_relit_exactement(mixed $enregistree, int|float $attendue): void
    {
        $this->assertSame($attendue, QuantiteArticles::normaliser($enregistree));
    }
}
