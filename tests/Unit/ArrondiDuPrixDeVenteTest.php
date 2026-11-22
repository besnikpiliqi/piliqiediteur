<?php

namespace Tests\Unit;

use App\Domain\Catalogue\Enums\ArrondiDuPrixDeVente;
use App\Domain\Catalogue\Services\BaremePrixDeVente;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les trois formules d'arrondi du prix de vente (décision de l'éditeur du
 * 22/11/2026) : chaque exemple de la maquette validée, le milieu qui monte,
 * le prix qui deviendrait 0 € et qui reste tel quel. Les prix sont des
 * centimes ; le barème de chaque exemple n'a ni bénéfice ni TVA, pour que le
 * prix d'achat soit le prix exact.
 */
class ArrondiDuPrixDeVenteTest extends TestCase
{
    /**
     * @return array<string, array{ArrondiDuPrixDeVente, int, int}>
     */
    public static function exemplesDeLEditeur(): array
    {
        return [
            'un prix en 9 : 77,60 € devient 79 €' => [ArrondiDuPrixDeVente::UnPrixEn9, 7760, 7900],
            'un prix en 9 : 74,00 € devient 69 €' => [ArrondiDuPrixDeVente::UnPrixEn9, 7400, 6900],
            'un prix en 9 : 134,40 € devient 129 €' => [ArrondiDuPrixDeVente::UnPrixEn9, 13440, 12900],
            'un prix en 9 : 75,00 € (le milieu) devient 79 €' => [ArrondiDuPrixDeVente::UnPrixEn9, 7500, 7900],
            'un prix en 9 : 5,00 € devient 9 €' => [ArrondiDuPrixDeVente::UnPrixEn9, 500, 900],
            'l\'euro : 18,40 € devient 18 €' => [ArrondiDuPrixDeVente::EuroLePlusProche, 1840, 1800],
            'l\'euro : 18,50 € devient 19 €' => [ArrondiDuPrixDeVente::EuroLePlusProche, 1850, 1900],
            'l\'euro : 18,55 € devient 19 €' => [ArrondiDuPrixDeVente::EuroLePlusProche, 1855, 1900],
            'cinq : 11,00 € devient 10 €' => [ArrondiDuPrixDeVente::MultipleDeCinq, 1100, 1000],
            'cinq : 12,50 € devient 15 €' => [ArrondiDuPrixDeVente::MultipleDeCinq, 1250, 1500],
            'cinq : 17,00 € devient 15 €' => [ArrondiDuPrixDeVente::MultipleDeCinq, 1700, 1500],
            'cinq : 17,50 € devient 20 €' => [ArrondiDuPrixDeVente::MultipleDeCinq, 1750, 2000],
        ];
    }

    #[DataProvider('exemplesDeLEditeur')]
    public function test_chaque_exemple_de_l_editeur_dans_sa_formule(ArrondiDuPrixDeVente $formule, int $avant, int $apres): void
    {
        $this->assertSame($apres, (new BaremePrixDeVente([], 0, $formule))->prixDeVente($avant * 100, 0)['prix_vente_ttc']);

        // La règle seule, sur le multiple du pas le plus proche du prix exact.
        $pas = $formule->pasEnCentimes();
        $multiple = intdiv($avant + intdiv($pas, 2), $pas) * $pas;
        $this->assertSame($apres, $formule->arrondir($multiple));
    }

    /**
     * @return array<string, array{ArrondiDuPrixDeVente, int, int}>
     */
    public static function milieux(): array
    {
        return [
            'l\'euro : 0,50 € monte à 1 €' => [ArrondiDuPrixDeVente::EuroLePlusProche, 50, 100],
            'l\'euro : 2,50 € monte à 3 €' => [ArrondiDuPrixDeVente::EuroLePlusProche, 250, 300],
            'cinq : 2,50 € monte à 5 €' => [ArrondiDuPrixDeVente::MultipleDeCinq, 250, 500],
            'un prix en 9 : 5,00 € monte à 9 €' => [ArrondiDuPrixDeVente::UnPrixEn9, 500, 900],
        ];
    }

    #[DataProvider('milieux')]
    public function test_le_milieu_monte(ArrondiDuPrixDeVente $formule, int $avant, int $apres): void
    {
        $this->assertSame($apres, (new BaremePrixDeVente([], 0, $formule))->prixDeVente($avant * 100, 0)['prix_vente_ttc']);
    }

    /**
     * @return array<string, array{ArrondiDuPrixDeVente, int}>
     */
    public static function prixQuiResteTelQuel(): array
    {
        return [
            'l\'euro : 0,40 €' => [ArrondiDuPrixDeVente::EuroLePlusProche, 40],
            'l\'euro : 0,49 €' => [ArrondiDuPrixDeVente::EuroLePlusProche, 49],
            'cinq : 2,40 €' => [ArrondiDuPrixDeVente::MultipleDeCinq, 240],
            'cinq : 2,49 €' => [ArrondiDuPrixDeVente::MultipleDeCinq, 249],
            'un prix en 9 : 0,40 €' => [ArrondiDuPrixDeVente::UnPrixEn9, 40],
            'un prix en 9 : 4,99 €' => [ArrondiDuPrixDeVente::UnPrixEn9, 499],
            'un prix à 0,00 €' => [ArrondiDuPrixDeVente::EuroLePlusProche, 0],
        ];
    }

    #[DataProvider('prixQuiResteTelQuel')]
    public function test_un_prix_qui_deviendrait_zero_reste_tel_quel(ArrondiDuPrixDeVente $formule, int $prix): void
    {
        $this->assertSame($prix, (new BaremePrixDeVente([], 0, $formule))->prixDeVente($prix * 100, 0)['prix_vente_ttc']);
        $this->assertNull($formule->arrondir(0));
    }

    public function test_sans_formule_rien_ne_s_arrondit(): void
    {
        $this->assertNull(ArrondiDuPrixDeVente::Aucun->pasEnCentimes());
        $this->assertNull(ArrondiDuPrixDeVente::Aucun->arrondir(1000));
        $this->assertNull(ArrondiDuPrixDeVente::Aucun->raisonDuPrixLaisseTel());
    }

    public function test_choisie_refuse_une_formule_inconnue(): void
    {
        foreach (['dix', 'aucun', null, 5, ''] as $formule) {
            $this->assertThrows(fn () => ArrondiDuPrixDeVente::choisie(true, $formule), InvalidArgumentException::class, ArrondiDuPrixDeVente::REFUS_FORMULE_INCONNUE);
        }

        $this->assertSame(ArrondiDuPrixDeVente::Aucun, ArrondiDuPrixDeVente::choisie(false, 'dix'), 'Case décochée, aucune formule : le champ masqué n\'est pas lu.');
        $this->assertSame(ArrondiDuPrixDeVente::EuroLePlusProche, ArrondiDuPrixDeVente::choisie(true, 'euro'));
        $this->assertSame([ArrondiDuPrixDeVente::UnPrixEn9, ArrondiDuPrixDeVente::EuroLePlusProche, ArrondiDuPrixDeVente::MultipleDeCinq], ArrondiDuPrixDeVente::formules());
        $this->assertSame(['aucun', 'x9', 'euro', 'cinq'], array_map(fn (ArrondiDuPrixDeVente $formule): string => $formule->value, ArrondiDuPrixDeVente::cases()));
    }
}
