<?php

namespace Tests\Unit;

use App\Domain\Catalogue\Enums\ArrondiDuPrixDeVente;
use App\Domain\Catalogue\Services\ExemplesDArrondi;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les exemples que la page « Prix de vente » montre de chaque formule : les
 * textes sont ceux de la maquette validée par l'éditeur le 22/11/2026, et
 * chaque nombre vient du calcul même du barème (voir
 * ArrondiDuPrixDeVenteTest et ArrondiDuPrixDeVenteAuHasardTest pour la règle).
 */
class ExemplesDArrondiTest extends TestCase
{
    public function test_la_phrase_de_chaque_formule_est_celle_de_la_maquette(): void
    {
        $this->assertSame('77,60 € devient 79 €, 74,00 € devient 69 €, 134,40 € devient 129 €. Un prix sous 5 € reste tel quel.', ExemplesDArrondi::phrase(ArrondiDuPrixDeVente::UnPrixEn9));
        $this->assertSame('18,40 € devient 18 €, 18,50 € devient 19 €, 18,55 € devient 19 €.', ExemplesDArrondi::phrase(ArrondiDuPrixDeVente::EuroLePlusProche));
        $this->assertSame('11,00 € devient 10 €, 12,50 € devient 15 €, 17,00 € devient 15 €, 17,50 € devient 20 €.', ExemplesDArrondi::phrase(ArrondiDuPrixDeVente::MultipleDeCinq));
    }

    public function test_la_page_propose_les_trois_formules_dans_leur_ordre(): void
    {
        $this->assertSame(['x9' => 'Un prix en 9', 'euro' => 'L\'euro le plus proche', 'cinq' => 'Le multiple de 5 € le plus proche'], ExemplesDArrondi::libelles());
        $this->assertSame(['x9', 'euro', 'cinq'], array_keys(ExemplesDArrondi::phrases()));
        $this->assertSame(ExemplesDArrondi::phrase(ArrondiDuPrixDeVente::EuroLePlusProche), ExemplesDArrondi::phrases()['euro']);
        $this->assertSame('L\'euro le plus proche. Prix de vente TTC avant et après l\'arrondi.', ExemplesDArrondi::legende(ArrondiDuPrixDeVente::EuroLePlusProche));
    }

    /**
     * @return array<string, array{ArrondiDuPrixDeVente, list<array{string, string, string}>}>
     */
    public static function tableaux(): array
    {
        return [
            'un prix en 9' => [ArrondiDuPrixDeVente::UnPrixEn9, [
                ['18,40 €', '19,00 €', '+0,60 €'], ['18,50 €', '19,00 €', '+0,50 €'], ['18,55 €', '19,00 €', '+0,45 €'],
                ['11,00 €', '9,00 €', '-2,00 €'], ['12,50 €', '9,00 €', '-3,50 €'], ['17,00 €', '19,00 €', '+2,00 €'],
                ['17,50 €', '19,00 €', '+1,50 €'], ['77,60 €', '79,00 €', '+1,40 €'], ['74,00 €', '69,00 €', '-5,00 €'],
                ['134,40 €', '129,00 €', '-5,40 €'],
            ]],
            'l\'euro le plus proche' => [ArrondiDuPrixDeVente::EuroLePlusProche, [
                ['18,40 €', '18,00 €', '-0,40 €'], ['18,50 €', '19,00 €', '+0,50 €'], ['18,55 €', '19,00 €', '+0,45 €'],
                ['11,00 €', '11,00 €', '–'], ['12,50 €', '13,00 €', '+0,50 €'], ['17,00 €', '17,00 €', '–'],
                ['17,50 €', '18,00 €', '+0,50 €'], ['77,60 €', '78,00 €', '+0,40 €'], ['74,00 €', '74,00 €', '–'],
                ['134,40 €', '134,00 €', '-0,40 €'],
            ]],
            'le multiple de 5 €' => [ArrondiDuPrixDeVente::MultipleDeCinq, [
                ['18,40 €', '20,00 €', '+1,60 €'], ['18,50 €', '20,00 €', '+1,50 €'], ['18,55 €', '20,00 €', '+1,45 €'],
                ['11,00 €', '10,00 €', '-1,00 €'], ['12,50 €', '15,00 €', '+2,50 €'], ['17,00 €', '15,00 €', '-2,00 €'],
                ['17,50 €', '20,00 €', '+2,50 €'], ['77,60 €', '80,00 €', '+2,40 €'], ['74,00 €', '75,00 €', '+1,00 €'],
                ['134,40 €', '135,00 €', '+0,60 €'],
            ]],
        ];
    }

    /**
     * @param  list<array{string, string, string}>  $attendu
     */
    #[DataProvider('tableaux')]
    public function test_le_tableau_de_chaque_formule_est_celui_de_la_maquette(ArrondiDuPrixDeVente $formule, array $attendu): void
    {
        $tableau = ExemplesDArrondi::tableau($formule);

        $this->assertSame($attendu, array_map(fn (array $ligne): array => [$ligne['avant'], $ligne['apres'], $ligne['ecart']], $tableau));
        $this->assertSame(
            array_map(fn (array $ligne): bool => $ligne[2] === '–', $attendu),
            array_map(fn (array $ligne): bool => $ligne['inchange'], $tableau),
            'Une ligne est inchangée quand son prix l\'est : son écart est « – ».',
        );
    }

    public function test_la_note_grise_dit_les_regles(): void
    {
        $this->assertSame(
            'Pour « L\'euro le plus proche » et « Le multiple de 5 € le plus proche », un prix pile au milieu monte : 18,50 € devient 19 € à l\'euro près ; 12,50 € devient 15 € et 17,50 € devient 20 € au multiple de 5 €. '
            .'Le milieu se juge sur le prix exact, avant son arrondi au centime : un prix exact de 10,495 €, qui s\'écrit 10,50 € au centime, n\'atteint pas le milieu et devient 10 € à l\'euro près. '
            .'Un prix qui deviendrait 0 € reste tel quel : 0,40 € reste 0,40 € à l\'euro près, 2,40 € reste 2,40 € au multiple de 5 €. '
            .'Pour un prix en 9, le milieu monte aussi : 75,00 € devient 79 €.',
            ExemplesDArrondi::regles(),
        );
    }

    public function test_un_prix_exact_s_ecrit_sans_zero_inutile_au_dela_du_centime(): void
    {
        $this->assertSame('10,495 €', ExemplesDArrondi::enEurosExact(1_049_500_000));
        $this->assertSame('10,49604 €', ExemplesDArrondi::enEurosExact(1_049_604_000));
        $this->assertSame('12,56 €', ExemplesDArrondi::enEurosExact(1_256_000_000));
        $this->assertSame('1 234,00 €', ExemplesDArrondi::enEurosExact(123_400_000_000));
        $this->assertSame('0,00000001 €', ExemplesDArrondi::enEurosExact(1));
    }
}
