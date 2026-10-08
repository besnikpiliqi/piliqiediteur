<?php

namespace Tests\Unit;

use App\Domain\Catalogue\Services\BaremePrixDeVente;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le calculateur de prix fourni par le magasin : prix de vente TTC =
 * (prix d'achat HT + bénéfice du palier) + TVA, arrondi au X9 s'il le
 * veut.
 */
class BaremePrixDeVenteTest extends TestCase
{
    /**
     * @return array<string, array{float, float}>
     */
    public static function prixDAchatEtBenefices(): array
    {
        return [
            'sous le premier palier' => [0.47, 10.0],
            'juste sous 2 €' => [1.99, 10.0],
            '2 € passe au palier suivant' => [2.0, 15.0],
            'juste sous 99 €' => [98.99, 50.0],
            '99 € passe au-delà' => [99.0, 60.0],
            'bien au-delà' => [500.0, 60.0],
        ];
    }

    #[DataProvider('prixDAchatEtBenefices')]
    public function test_le_benefice_depend_du_palier_du_prix_dachat(float $prixAchatHt, float $benefice): void
    {
        $this->assertSame($benefice, BaremePrixDeVente::parDefaut()->beneficePour($prixAchatHt));
    }

    public function test_le_prix_de_vente_ajoute_le_benefice_au_prix_dachat_puis_la_tva(): void
    {
        $bareme = BaremePrixDeVente::parDefaut();

        $this->assertSame(['prix_vente_ttc' => 12.56, 'prix_cible_ttc' => 12.56, 'benefice' => 10.0], $bareme->prixDeVente(0.47, 20));
        $this->assertSame(192.0, $bareme->prixDeVente(100, 20)['prix_vente_ttc']);
        $this->assertSame(63.3, $bareme->prixDeVente(20, 5.5)['prix_vente_ttc'], '(20 + 40) x 1,055 = 63,30.');
    }

    /**
     * @return array<string, array{float, float}>
     */
    public static function prixArrondisAuX9(): array
    {
        return [
            '77,60 monte' => [77.60, 79.0],
            '74,00 descend' => [74.00, 69.0],
            '134,40 descend' => [134.40, 129.0],
            '184,80 monte' => [184.80, 179.0],
            '75,00 monte' => [75.00, 79.0],
            '5,00 donne 9' => [5.00, 9.0],
            'sous 5 € reste tel quel' => [4.99, 4.99],
        ];
    }

    #[DataProvider('prixArrondisAuX9')]
    public function test_l_arrondi_ramene_le_prix_au_x9_le_plus_proche(float $prixCibleTtc, float $prixArrondi): void
    {
        $sansBeneficeNiTva = new BaremePrixDeVente([], 0, true);

        $this->assertSame($prixArrondi, $sansBeneficeNiTva->prixDeVente($prixCibleTtc, 0)['prix_vente_ttc']);
    }

    /**
     * Sans bénéfice au-delà du dernier palier, le barème ne propose rien
     * pour un article plus cher : son prix de vente se saisit à la main.
     */
    public function test_sans_benefice_au_dela_un_article_plus_cher_n_a_pas_de_prix_propose(): void
    {
        $bareme = new BaremePrixDeVente([['jusqu_a' => 50.0, 'benefice' => 20.0]], null, false);

        $this->assertSame(60.0, $bareme->prixDeVente(30, 20)['prix_vente_ttc']);
        $this->assertNull($bareme->beneficePour(50));
        $this->assertNull($bareme->prixDeVente(80, 20));
    }

    public function test_un_bareme_vide_est_refuse(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BaremePrixDeVente([], null, false);
    }

    /**
     * L'arrondi au X9 part du prix cible exact, comme le calculateur du
     * magasin, et jamais du prix déjà arrondi au centime : (10,83 € + 35 €)
     * x 1,2 = 54,996 € reste sous la demi-dizaine et donne 49 €, même si le
     * prix cible affiché, au centime, est 55,00 €.
     *
     * @return array<string, array{float, float, float, float}>
     */
    public static function prixCiblesJusteSousLaDemiDizaine(): array
    {
        return [
            '54,996 € à 20 %' => [10.83, 20, 49.0, 55.0],
            '114,995 € à 5,5 %' => [64.00, 5.5, 109.0, 115.0],
            '64,999 € à 10 %' => [19.09, 10, 59.0, 65.0],
        ];
    }

    #[DataProvider('prixCiblesJusteSousLaDemiDizaine')]
    public function test_l_arrondi_x9_part_du_prix_cible_exact(float $prixAchatHt, float $tauxTva, float $prixArrondi, float $prixCibleAffiche): void
    {
        $bareme = new BaremePrixDeVente(BaremePrixDeVente::parDefaut()->paliers, 60, true);

        $this->assertSame(['prix_vente_ttc' => $prixArrondi, 'prix_cible_ttc' => $prixCibleAffiche], array_intersect_key(
            $bareme->prixDeVente($prixAchatHt, $tauxTva),
            ['prix_vente_ttc' => true, 'prix_cible_ttc' => true],
        ));
    }

    public function test_un_palier_en_double_est_refuse(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BaremePrixDeVente([['jusqu_a' => 2.0, 'benefice' => 10.0], ['jusqu_a' => 2.0, 'benefice' => 15.0]], 60, false);
    }

    public function test_un_benefice_negatif_est_refuse(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new BaremePrixDeVente([['jusqu_a' => 2.0, 'benefice' => -1.0]], 60, false);
    }

    public function test_les_paliers_sont_ranges_par_prix_dachat(): void
    {
        $bareme = BaremePrixDeVente::depuisTableau([
            'paliers' => [['jusqu_a' => '10', 'benefice' => '30'], ['jusqu_a' => '2', 'benefice' => '10']],
            'benefice_au_dela' => '60',
            'arrondi_x9' => false,
        ]);

        $this->assertSame(10.0, $bareme->beneficePour(1));
        $this->assertSame(30.0, $bareme->beneficePour(5));
    }
}
