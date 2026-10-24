<?php

namespace Tests\Unit;

use App\Domain\Facturation\Enums\TerritoireTva;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TerritoireTvaTest extends TestCase
{
    /**
     * @return array<string, array{string|null, string|null, TerritoireTva|null}>
     */
    public static function adresses(): array
    {
        return [
            'Paris' => ['FR', '75001', TerritoireTva::Metropole],
            'Ajaccio' => ['FR', '20000', TerritoireTva::Corse],
            'Fort-de-France' => ['FR', '97200', TerritoireTva::AntillesReunion],
            'Pointe-à-Pitre' => ['FR', '97110', TerritoireTva::AntillesReunion],
            'Saint-Denis de La Réunion, écrit avec une espace' => ['fr', '974 00', TerritoireTva::AntillesReunion],
            'Cayenne' => ['FR', '97300', TerritoireTva::GuyaneMayotte],
            'Mamoudzou' => ['FR', '97600', TerritoireTva::GuyaneMayotte],
            'Saint-Barthélemy' => ['FR', '97133', null],
            'Saint-Martin' => ['FR', '97150', null],
            'Saint-Pierre-et-Miquelon' => ['FR', '97500', null],
            'Nouméa' => ['FR', '98800', null],
            'sans code postal' => ['FR', null, TerritoireTva::Metropole],
            'Bruxelles' => ['BE', '1000', null],
        ];
    }

    #[DataProvider('adresses')]
    public function test_le_territoire_se_deduit_du_pays_et_du_code_postal(?string $pays, ?string $codePostal, ?TerritoireTva $attendu): void
    {
        $this->assertSame($attendu, TerritoireTva::depuisAdresse($pays, $codePostal));
    }

    /**
     * @return array<string, array{TerritoireTva, float}>
     */
    public static function tauxNormaux(): array
    {
        return [
            'métropole' => [TerritoireTva::Metropole, 20.0],
            'Corse' => [TerritoireTva::Corse, 20.0],
            'Guadeloupe, Martinique et La Réunion' => [TerritoireTva::AntillesReunion, 8.5],
            'Guyane et Mayotte' => [TerritoireTva::GuyaneMayotte, 0.0],
        ];
    }

    #[DataProvider('tauxNormaux')]
    public function test_le_taux_propose_est_le_taux_normal_du_territoire(TerritoireTva $territoire, float $attendu): void
    {
        $this->assertSame($attendu, $territoire->tauxNormal());
    }

    public function test_seuls_la_guyane_et_mayotte_ne_sont_pas_encore_pris_en_charge(): void
    {
        $this->assertSame(
            [TerritoireTva::GuyaneMayotte],
            array_values(array_filter(TerritoireTva::cases(), fn (TerritoireTva $territoire): bool => ! $territoire->estPrisEnCharge())),
        );
    }

    /**
     * @return array<string, array{TerritoireTva, mixed, string|null}>
     */
    public static function tauxSaisis(): array
    {
        return [
            'taux réduit de métropole' => [TerritoireTva::Metropole, '5.5', null],
            'ancien taux normal en métropole' => [TerritoireTva::Metropole, '19.6', 'Taux inhabituel pour un magasin en métropole (en vigueur : 20 ; 10 ; 5,5 ; 2,1 %) : vérifiez votre saisie.'],
            'taux de la Corse' => [TerritoireTva::Corse, 13, null],
            'taux normal des Antilles' => [TerritoireTva::AntillesReunion, '8.5', null],
            'taux de métropole aux Antilles' => [TerritoireTva::AntillesReunion, 20, 'Taux inhabituel pour un magasin en Guadeloupe, en Martinique ou à La Réunion (en vigueur : 8,5 ; 2,1 ; 1,75 ; 1,05 %) : vérifiez votre saisie.'],
            'saisie en cours' => [TerritoireTva::Metropole, '', null],
        ];
    }

    #[DataProvider('tauxSaisis')]
    public function test_un_taux_hors_de_ceux_en_vigueur_est_signale(TerritoireTva $territoire, mixed $taux, ?string $aide): void
    {
        $this->assertSame($aide, $territoire->aideSiTauxInhabituel($taux));
    }
}
