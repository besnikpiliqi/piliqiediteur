<?php

namespace Tests\Unit;

use App\Domain\Facturation\Enums\TerritoireTva;
use App\Domain\Tenancy\Enums\Territoire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Le territoire d'un magasin (voir Territoire), lu au répertoire Sirene :
 * à la commune de son établissement, à défaut à son code postal.
 */
class TerritoireTest extends TestCase
{
    /**
     * @return array<string, array{string, string|null, Territoire|null}>
     */
    public static function communes(): array
    {
        return [
            'Paris' => ['75056', '75001', Territoire::Metropole],
            'Ajaccio, Corse-du-Sud' => ['2A004', '20000', Territoire::Corse],
            'Bastia, Haute-Corse' => ['2B033', '20200', Territoire::Corse],
            'Les Abymes, Guadeloupe' => ['97101', '97139', Territoire::Guadeloupe],
            'Basse-Terre, Guadeloupe' => ['97105', '97100', Territoire::Guadeloupe],
            'Fort-de-France, Martinique' => ['97209', '97200', Territoire::Martinique],
            'Cayenne, Guyane' => ['97302', '97300', Territoire::Guyane],
            'Saint-Denis, La Réunion' => ['97411', '97400', Territoire::Reunion],
            'Mamoudzou, Mayotte' => ['97611', '97600', Territoire::Mayotte],
            'Saint-Barthélemy, au code postal de la Guadeloupe' => ['97701', '97133', null],
            'Saint-Martin, au code postal de la Guadeloupe' => ['97801', '97150', null],
            'Saint-Pierre-et-Miquelon' => ['97502', '97500', null],
            'Nouméa' => ['98818', '98800', null],
            'commune de Martinique sans code postal (diffusion partielle)' => ['97209', null, Territoire::Martinique],
            'commune de Martinique, code postal de métropole' => ['97209', '75001', Territoire::Martinique],
        ];
    }

    #[DataProvider('communes')]
    public function test_le_territoire_se_lit_au_code_commune(string $codeCommune, ?string $codePostal, ?Territoire $attendu): void
    {
        $this->assertSame($attendu, Territoire::depuisLeRepertoire($codeCommune, $codePostal));
    }

    /**
     * @return array<string, array{string|null, string|null, Territoire|null}>
     */
    public static function adresses(): array
    {
        return [
            'Paris' => ['FR', '75001', Territoire::Metropole],
            'Ajaccio' => ['FR', '20000', Territoire::Corse],
            'Fort-de-France' => ['FR', '97200', Territoire::Martinique],
            'Pointe-à-Pitre' => ['FR', '97110', Territoire::Guadeloupe],
            'Saint-Denis de La Réunion, écrit avec une espace' => ['fr', '974 00', Territoire::Reunion],
            'Cayenne' => ['FR', '97300', Territoire::Guyane],
            'Mamoudzou' => ['FR', '97600', Territoire::Mayotte],
            'Saint-Barthélemy' => ['FR', '97133', null],
            'Saint-Martin' => ['FR', '97150', null],
            'Saint-Pierre-et-Miquelon' => ['FR', '97500', null],
            'Nouméa' => ['FR', '98800', null],
            'sans code postal' => ['FR', null, Territoire::Metropole],
            'Bruxelles' => ['BE', '1000', null],
        ];
    }

    /**
     * Le code postal, d'une adresse saisie, ou du répertoire quand il ne
     * diffuse pas la commune de l'établissement.
     */
    #[DataProvider('adresses')]
    public function test_le_territoire_se_deduit_du_pays_et_du_code_postal(?string $pays, ?string $codePostal, ?Territoire $attendu): void
    {
        $this->assertSame($attendu, Territoire::depuisCodePostal($pays, $codePostal));

        if ($pays === 'FR' && $codePostal !== null) {
            $this->assertSame($attendu, Territoire::depuisLeRepertoire(null, $codePostal));
        }
    }

    public function test_sans_code_commune_ni_code_postal_le_territoire_est_inconnu(): void
    {
        $this->assertNull(Territoire::depuisLeRepertoire(null, null));
        $this->assertNull(Territoire::depuisLeRepertoire('', ' '));
    }

    /**
     * @return array<string, array{Territoire, TerritoireTva, float}>
     */
    public static function territoiresDeTva(): array
    {
        return [
            'métropole' => [Territoire::Metropole, TerritoireTva::Metropole, 20.0],
            'Corse' => [Territoire::Corse, TerritoireTva::Corse, 20.0],
            'Guadeloupe' => [Territoire::Guadeloupe, TerritoireTva::AntillesReunion, 8.5],
            'Martinique' => [Territoire::Martinique, TerritoireTva::AntillesReunion, 8.5],
            'La Réunion' => [Territoire::Reunion, TerritoireTva::AntillesReunion, 8.5],
            'Guyane' => [Territoire::Guyane, TerritoireTva::GuyaneMayotte, 0.0],
            'Mayotte' => [Territoire::Mayotte, TerritoireTva::GuyaneMayotte, 0.0],
        ];
    }

    #[DataProvider('territoiresDeTva')]
    public function test_chaque_territoire_donne_son_territoire_de_tva_et_son_taux(Territoire $territoire, TerritoireTva $tva, float $taux): void
    {
        $this->assertSame($tva, $territoire->tva());
        $this->assertSame($taux, $territoire->tva()->tauxNormal());
    }

    public function test_seuls_la_guyane_et_mayotte_ne_sont_pas_encore_pris_en_charge(): void
    {
        $this->assertSame(
            [Territoire::Guyane, Territoire::Mayotte],
            array_values(array_filter(Territoire::cases(), fn (Territoire $territoire): bool => ! $territoire->estPrisEnCharge())),
        );
    }

    /**
     * @return array<string, array{Territoire, string|null}>
     */
    public static function departements(): array
    {
        return [
            'métropole' => [Territoire::Metropole, null],
            'Corse' => [Territoire::Corse, null],
            'Guadeloupe' => [Territoire::Guadeloupe, '971'],
            'Martinique' => [Territoire::Martinique, '972'],
            'Guyane' => [Territoire::Guyane, '973'],
            'La Réunion' => [Territoire::Reunion, '974'],
            'Mayotte' => [Territoire::Mayotte, '976'],
        ];
    }

    #[DataProvider('departements')]
    public function test_le_departement_d_outre_mer_se_deduit_du_territoire(Territoire $territoire, ?string $attendu): void
    {
        $this->assertSame($attendu, $territoire->departement());
    }
}
