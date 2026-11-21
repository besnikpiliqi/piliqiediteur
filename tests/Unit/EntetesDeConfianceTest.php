<?php

namespace Tests\Unit;

use App\Domain\Tenancy\Support\EntetesDeConfiance;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Les en-têtes de confiance d'un mandataire (voir EntetesDeConfiance) : un
 * masque des six en-têtes que Symfony connaît, de 0 à 63, rendu tel quel ;
 * un autre nombre est refusé.
 */
class EntetesDeConfianceTest extends TestCase
{
    public function test_un_masque_des_en_tetes_de_symfony_est_rendu_tel_quel(): void
    {
        $tous = Request::HEADER_FORWARDED | Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PREFIX;

        $this->assertSame(63, $tous);
        $this->assertSame($tous, EntetesDeConfiance::masque($tous));
        $this->assertSame(0, EntetesDeConfiance::masque(0));
        $this->assertSame(Request::HEADER_X_FORWARDED_FOR, EntetesDeConfiance::masque(Request::HEADER_X_FORWARDED_FOR));
    }

    /**
     * @return array<string, array{int}>
     */
    public static function horsDuMasque(): array
    {
        return [
            'négatif' => [-1],
            'au-delà des six en-têtes' => [64],
        ];
    }

    #[DataProvider('horsDuMasque')]
    public function test_un_nombre_hors_du_masque_est_refuse(int $entetes): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf(EntetesDeConfiance::REFUS, $entetes));

        EntetesDeConfiance::masque($entetes);
    }
}
