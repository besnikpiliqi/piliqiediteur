<?php

namespace Tests\Unit;

use App\Domain\Tenancy\Support\SensDuTri;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le sens d'un tri reçu du navigateur (voir SensDuTri), lu comme le
 * constructeur de requêtes le lit : « asc » ou « desc », sans tenir compte
 * de la casse ; tout autre sens est refusé avec son message.
 */
class SensDuTriTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function sensAcceptes(): array
    {
        return [
            'croissant' => ['asc', 'asc'],
            'décroissant' => ['desc', 'desc'],
            'majuscules' => ['DESC', 'desc'],
            'casse mêlée' => ['Asc', 'asc'],
        ];
    }

    #[DataProvider('sensAcceptes')]
    public function test_asc_et_desc_sont_lus_sans_tenir_compte_de_la_casse(string $sens, string $lu): void
    {
        $this->assertSame($lu, SensDuTri::de($sens));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sensRefuses(): array
    {
        return [
            'vide' => [''],
            'mot entier' => ['ascending'],
            'espace autour' => [' asc'],
            'injection' => ['asc, (select 1)'],
        ];
    }

    #[DataProvider('sensRefuses')]
    public function test_un_autre_sens_est_refuse(string $sens): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(SensDuTri::REFUS);

        SensDuTri::de($sens);
    }
}
