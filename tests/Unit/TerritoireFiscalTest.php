<?php

namespace Tests\Unit;

use App\Domain\Facturation\Services\TerritoireFiscal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TerritoireFiscalTest extends TestCase
{
    /**
     * @return array<string, array{string|null, string|null}>
     */
    public static function numerosTva(): array
    {
        return [
            'Allemagne' => ['DE123456789', 'DE'],
            'France' => ['FR44732829320', 'FR'],
            'Grèce, préfixe EL' => ['EL123456789', 'EL'],
            'Grèce écrite avec son code de pays' => ['GR123456789', null],
            'Irlande du Nord' => ['XI123456789', 'XI'],
            'Royaume-Uni hors Irlande du Nord' => ['GB123456789', null],
            'Suisse' => ['CHE123456789', null],
            'saisi en minuscules, avec des espaces' => ['be 0123.456.789', 'BE'],
            'sans n°' => [null, null],
        ];
    }

    #[DataProvider('numerosTva')]
    public function test_seuls_les_numeros_de_l_union_et_d_irlande_du_nord_ont_un_prefixe_que_vies_verifie(?string $numeroTva, ?string $prefixe): void
    {
        $this->assertSame($prefixe, TerritoireFiscal::prefixeTvaUe($numeroTva));
    }
}
