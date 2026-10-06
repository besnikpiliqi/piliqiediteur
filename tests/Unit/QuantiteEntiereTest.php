<?php

namespace Tests\Unit;

use App\Domain\Facturation\Services\QuantiteEntiere;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class QuantiteEntiereTest extends TestCase
{
    /**
     * @return array<string, array{mixed, int}>
     */
    public static function ecrituresDunEntier(): array
    {
        return [
            'entier' => [2, 2],
            'texte du formulaire' => ['3', 3],
            'flottant entier' => [4.0, 4],
            'espaces autour' => [' 5 ', 5],
            'grande quantité' => ['1000', 1000],
        ];
    }

    #[DataProvider('ecrituresDunEntier')]
    public function test_une_quantite_entiere_est_acceptee_telle_quelle(mixed $saisie, int $attendue): void
    {
        $this->assertSame($attendue, QuantiteEntiere::depuisSaisie($saisie));
    }

    /**
     * Rien n'est jamais arrondi ni tronqué : 1,5 refusé, pas compté 1.
     *
     * @return array<string, array{mixed}>
     */
    public static function saisiesRefusees(): array
    {
        return [
            'un et demi' => [1.5],
            'un et demi en texte' => ['1.5'],
            'virgule française' => ['1,5'],
            'décimales à zéro' => ['2.000'],
            'zéro' => [0],
            'négatif' => [-1],
            'vide' => [''],
            'absente' => [null],
            'texte' => ['deux'],
            'booléen' => [true],
        ];
    }

    #[DataProvider('saisiesRefusees')]
    public function test_une_quantite_non_entiere_ou_inferieure_a_1_est_refusee(mixed $saisie): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("La quantité doit être un nombre entier d'articles, au moins 1.");

        QuantiteEntiere::depuisSaisie($saisie);
    }
}
