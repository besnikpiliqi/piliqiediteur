<?php

namespace Tests\Unit;

use App\Domain\Facturation\Services\SaisieDecimale;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SaisieDecimaleTest extends TestCase
{
    /**
     * Une notation scientifique se lit par son écriture décimale, arrondie
     * une fois au centième comme toute saisie.
     *
     * @return array<string, array{string, int}>
     */
    public static function notationsScientifiques(): array
    {
        return [
            'mille' => ['1e3', 100000],
            'majuscule et négatif' => ['-2.5E1', -2500],
            'demi-centième' => ['8.325e0', 833],
        ];
    }

    #[DataProvider('notationsScientifiques')]
    public function test_une_notation_scientifique_se_lit_par_son_ecriture_decimale(string $saisie, int $centiemes): void
    {
        $this->assertSame($centiemes, SaisieDecimale::enCentiemes($saisie));
    }

    /**
     * Un nombre trop grand pour un flottant (« 1e400 ») n'a pas d'écriture
     * décimale : il est refusé, jamais lu zéro, comme un nombre au-delà de
     * la borne du calcul.
     *
     * @return array<string, array{string}>
     */
    public static function nombresHorsLimites(): array
    {
        return [
            'infini positif' => ['1e400'],
            'infini négatif' => ['-1e400'],
            'au-delà de la borne' => ['1e20'],
        ];
    }

    #[DataProvider('nombresHorsLimites')]
    public function test_un_nombre_hors_limites_est_refuse_jamais_lu_zero(string $saisie): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Montant hors limites.'));

        SaisieDecimale::enCentiemes($saisie);
    }
}
