<?php

namespace Tests\Unit;

use App\Domain\Facturation\Enums\TerritoireTva;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TerritoireTvaTest extends TestCase
{
    /**
     * Les taux en centièmes de point : 2000 pour 20 %.
     *
     * @return array<string, array{TerritoireTva, int}>
     */
    public static function tauxNormaux(): array
    {
        return [
            'métropole' => [TerritoireTva::Metropole, 2000],
            'Corse' => [TerritoireTva::Corse, 2000],
            'Guadeloupe, Martinique et La Réunion' => [TerritoireTva::AntillesReunion, 850],
            'Guyane et Mayotte' => [TerritoireTva::GuyaneMayotte, 0],
        ];
    }

    #[DataProvider('tauxNormaux')]
    public function test_le_taux_propose_est_le_taux_normal_du_territoire(TerritoireTva $territoire, int $attendu): void
    {
        $this->assertSame($attendu, $territoire->tauxNormal());
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

    /**
     * Les taux particuliers de la Corse et des départements d'outre-mer
     * se saisissent sans avertissement (décisions de Claude T25.1, T25.3,
     * T25.4 et T25.5 de l'onglet « À faire vérifier par un
     * professionnel ») : en Corse, 10 % (alcool servi sur place), 13 % et
     * 2,1 % (alimentation, livres) ; en Guadeloupe, en Martinique et à La
     * Réunion, 2,1 %, 1,75 % (animaux vivants de boucherie) et 1,05 %
     * (presse, spectacles).
     *
     * @return array<string, array{TerritoireTva, string}>
     */
    public static function tauxParticuliers(): array
    {
        return [
            'Corse, 10 %' => [TerritoireTva::Corse, '10'],
            'Corse, 13 %' => [TerritoireTva::Corse, '13'],
            'Corse, 2,1 %' => [TerritoireTva::Corse, '2.1'],
            'Antilles et La Réunion, 2,1 %' => [TerritoireTva::AntillesReunion, '2.1'],
            'Antilles et La Réunion, 1,75 %' => [TerritoireTva::AntillesReunion, '1.75'],
            'Antilles et La Réunion, 1,05 %' => [TerritoireTva::AntillesReunion, '1.05'],
        ];
    }

    #[DataProvider('tauxParticuliers')]
    public function test_les_taux_particuliers_de_la_corse_et_des_departements_d_outre_mer_passent_sans_avertissement(TerritoireTva $territoire, string $taux): void
    {
        $this->assertNull($territoire->aideSiTauxInhabituel($taux));
    }
}
