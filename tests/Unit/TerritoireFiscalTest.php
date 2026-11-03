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
            'France' => ['FR88100000009', 'FR'],
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

    /**
     * @return array<string, array{string, string|null, string|null}>
     */
    public static function departementsDOutreMer(): array
    {
        return [
            'Fort-de-France' => ['FR', '97200', '972'],
            'Pointe-à-Pitre par son code de pays' => ['GP', '97110', '971'],
            'Mamoudzou par son code de pays' => ['YT', null, '976'],
            'Saint-Denis de La Réunion, écrit avec une espace' => ['fr', '974 00', '974'],
            'Saint-Barthélemy, collectivité au code postal de la Guadeloupe' => ['FR', '97133', null],
            'Saint-Pierre-et-Miquelon' => ['FR', '97500', null],
            'Paris' => ['FR', '75001', null],
            'Bruxelles' => ['BE', '97200', null],
        ];
    }

    #[DataProvider('departementsDOutreMer')]
    public function test_le_departement_d_outre_mer_se_lit_au_pays_ou_au_code_postal(string $pays, ?string $codePostal, ?string $departement): void
    {
        $this->assertSame($departement, TerritoireFiscal::departementDom($pays, $codePostal));
    }

    /**
     * Où partent des biens, et où est établi le client d'un service :
     * hors de l'Union pour un export ou une prestation hors UE, dans un
     * autre État membre pour une livraison intracommunautaire ou une
     * prestation autoliquidée. Monaco suit la TVA française ; l'Irlande du
     * Nord suit l'Union pour les biens, pas pour les services.
     *
     * @return array<string, array{string, string, bool, bool, bool, bool}>
     */
    public static function destinations(): array
    {
        return [
            'Berlin' => ['DE', '10115', false, false, true, true],
            'Lyon' => ['FR', '69001', false, false, false, false],
            'Monaco' => ['MC', '98000', false, false, false, false],
            'New York' => ['US', '10001', true, true, false, false],
            'Belfast, Irlande du Nord' => ['GB', 'BT1 1AA', false, true, true, false],
            'Londres' => ['GB', 'SW1A 1AA', true, true, false, false],
            'Las Palmas, îles Canaries' => ['ES', '35001', true, true, false, false],
            'Santa Cruz de Tenerife, îles Canaries' => ['ES', '38001', true, true, false, false],
            'Madrid' => ['ES', '28001', false, false, true, true],
            'Ceuta' => ['ES', '51001', true, true, false, false],
            'Mariehamn, îles Åland' => ['FI', '22100', true, true, false, false],
            'Helsinki' => ['FI', '00100', false, false, true, true],
            'mont Athos' => ['GR', '63086', true, true, false, false],
            'Heligoland' => ['DE', '27498', true, true, false, false],
            'Büsingen' => ['DE', '78266', true, true, false, false],
            'Livigno' => ['IT', '23041', true, true, false, false],
            'Campione d\'Italia' => ['IT', '22061', true, true, false, false],
            'Fort-de-France' => ['FR', '97200', true, true, false, false],
            'Papeete, Polynésie française' => ['FR', '98714', true, true, false, false],
            'Saint-Martin par son code de pays' => ['MF', '97150', true, true, false, false],
            'Genève' => ['CH', '1201', true, true, false, false],
        ];
    }

    #[DataProvider('destinations')]
    public function test_la_destination_d_une_vente_hors_de_france_se_lit_au_pays_et_au_code_postal(string $pays, string $codePostal, bool $horsUePourDesBiens, bool $horsUePourDesServices, bool $destinationUePourDesBiens, bool $autreEtatMembrePourDesServices): void
    {
        $this->assertSame(
            [$horsUePourDesBiens, $horsUePourDesServices, $destinationUePourDesBiens, $autreEtatMembrePourDesServices],
            [
                TerritoireFiscal::estHorsUePourDesBiens($pays, $codePostal),
                TerritoireFiscal::estHorsUePourDesServices($pays, $codePostal),
                TerritoireFiscal::estDestinationUePourDesBiens($pays, $codePostal),
                TerritoireFiscal::estDansUnAutreEtatMembrePourDesServices($pays, $codePostal),
            ],
        );
    }
}
