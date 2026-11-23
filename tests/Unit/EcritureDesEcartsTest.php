<?php

namespace Tests\Unit;

use App\Domain\TableauDeBord\Support\EcritureDesEcarts;
use App\Domain\TableauDeBord\Support\Mois;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Les formats de la carte « Écart des prix d'achat » (A5 et A6 de la
 * maquette) : les montants, les prix, les signes, les milliers et leurs
 * caractères exacts (U+202F, U+00A0, U+0020, U+2212, U+00D7), le pourcentage
 * arrondi d'après l'écriture décimale la plus courte du quotient comme le
 * fait la maquette, les nombres à virgule écrits comme JavaScript les écrit
 * et les graduations de l'axe.
 */
class EcritureDesEcartsTest extends TestCase
{
    private const FINE = "\u{202F}";

    private const INSECABLE = "\u{00A0}";

    private const MOINS = "\u{2212}";

    public function test_un_montant_s_ecrit_avec_l_espace_fine_entre_les_milliers_et_l_espace_insecable_avant_l_euro(): void
    {
        $this->assertSame('15'.self::FINE.'928,61'.self::INSECABLE.'€', EcritureDesEcarts::euros(1592861));
        $this->assertSame('0,00'.self::INSECABLE.'€', EcritureDesEcarts::euros(0));
        $this->assertSame('0,05'.self::INSECABLE.'€', EcritureDesEcarts::euros(5));
        $this->assertSame('1'.self::FINE.'000'.self::FINE.'000,00'.self::INSECABLE.'€', EcritureDesEcarts::euros(100000000));
        $this->assertSame('999,99'.self::INSECABLE.'€', EcritureDesEcarts::euros(99999));
    }

    public function test_un_montant_negatif_s_ecrit_avec_le_signe_ascii_comme_la_maquette(): void
    {
        $this->assertSame('-0,05'.self::INSECABLE.'€', EcritureDesEcarts::euros(-5));
        $this->assertSame('-1'.self::FINE.'234,56 €', EcritureDesEcarts::prix(-123456));
    }

    public function test_un_prix_unitaire_s_ecrit_avec_une_espace_ordinaire_avant_l_euro(): void
    {
        $this->assertSame('19,54 €', EcritureDesEcarts::prix(1954));
        $this->assertSame('1'.self::FINE.'234,56 €', EcritureDesEcarts::prix(123456));
        $this->assertSame('0,00 €', EcritureDesEcarts::prix(0));
        $this->assertStringNotContainsString(self::INSECABLE, EcritureDesEcarts::prix(1954));
    }

    public function test_un_montant_signe_prend_plus_moins_ou_rien(): void
    {
        $this->assertSame('+22,80'.self::INSECABLE.'€', EcritureDesEcarts::signe(2280));
        $this->assertSame(self::MOINS.'4,57'.self::INSECABLE.'€', EcritureDesEcarts::signe(-457));
        $this->assertSame('0,00'.self::INSECABLE.'€', EcritureDesEcarts::signe(0));
        $this->assertSame('+1'.self::FINE.'000,00'.self::INSECABLE.'€', EcritureDesEcarts::signe(100000));
    }

    public function test_un_entier_a_l_espace_fine_entre_les_milliers(): void
    {
        $this->assertSame('1'.self::FINE.'510', EcritureDesEcarts::entier(1510));
        $this->assertSame('999', EcritureDesEcarts::entier(999));
        $this->assertSame('0', EcritureDesEcarts::entier(0));
        $this->assertSame('1'.self::FINE.'000'.self::FINE.'000', EcritureDesEcarts::entier(1000000));
        $this->assertSame('-1'.self::FINE.'510', EcritureDesEcarts::entier(-1510));
    }

    public function test_un_montant_au_dela_des_entiers_surs_est_refuse(): void
    {
        $this->assertSame('90'.self::FINE.'071'.self::FINE.'992'.self::FINE.'547'.self::FINE.'409,91'.self::INSECABLE.'€', EcritureDesEcarts::euros(9007199254740991));

        foreach ([
            fn () => EcritureDesEcarts::euros(9007199254740992),
            fn () => EcritureDesEcarts::prix(-9007199254740992),
            fn () => EcritureDesEcarts::signe(PHP_INT_MAX),
            fn () => EcritureDesEcarts::signe(PHP_INT_MIN),
            fn () => EcritureDesEcarts::entier(9007199254740992),
            fn () => EcritureDesEcarts::pourcentage(9007199254740992, 1),
            fn () => EcritureDesEcarts::pourcentage(1, 9007199254740992),
        ] as $appel) {
            try {
                $appel();
                $this->fail('Un montant hors des entiers sûrs doit être refusé.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Les exemples de A5 et les cas limites : [écart, base, écriture].
     *
     * @return array<string, array{0: int, 1: int, 2: string}>
     */
    public static function pourcentages(): array
    {
        $f = self::FINE;
        $m = self::MOINS;

        return [
            'déc. 2025 : -0,5 %' => [-457, 90085, $m.'0,5 %'],
            'févr. 2026 : +3,2 %' => [2280, 71550, '+3,2 %'],
            'mars 2026 : +0,0 %, le signe est celui de l’écart' => [9, 44983, '+0,0 %'],
            'les 12 mois : +1,8 %' => [28136, 1564725, '+1,8 %'],
            'écart nul' => [0, 100, '0,0 %'],
            'base nulle' => [500, 0, '—'],
            'base nulle et écart nul' => [0, 0, '—'],
            'écart négatif, le signe est celui de l’écart' => [-9, 2000, $m.'0,4 %'],
            '9 ÷ 2 000 donne 0,4 % : le quotient s’écrit 0,44999999999999996' => [9, 2000, '+0,4 %'],
            'un demi exact qui s’écrit juste : 0,05 donne 0,1' => [1, 2000, '+0,1 %'],
            'un demi exact qui s’écrit juste : 0,15 donne 0,2' => [3, 2000, '+0,2 %'],
            'un demi exact qui s’écrit juste : 1,25 donne 1,3' => [1, 80, '+1,3 %'],
            'retenue : 0,995 donne 1,0' => [199, 20000, '+1,0 %'],
            'retenue : 9,995 donne 10,0' => [1999, 20000, '+10,0 %'],
            'retenue : 99,95 donne 100,0' => [1999, 2000, '+100,0 %'],
            'retenue qui traverse un séparateur : 999,9500249875063 donne 1 000,0' => [20009, 2001, '+1'.$f.'000,0 %'],
            'quotient à milliers' => [10000, 1, '+1'.$f.'000'.$f.'000,0 %'],
            'quotient énorme' => [9007199254740991, 1, '+900'.$f.'719'.$f.'925'.$f.'474'.$f.'099'.$f.'100,0 %'],
            'écriture à exposant positif du quotient (1,1102230246251569e-14)' => [1, 9007199254740991, '+0,0 %'],
            'écriture à exposant, écart négatif' => [-1, 9007199254740991, $m.'0,0 %'],
            'écriture positionnelle d’un petit quotient (0,045)' => [45, 100000, '+0,0 %'],
        ];
    }

    #[DataProvider('pourcentages')]
    public function test_le_pourcentage(int $ecart, int $base, string $attendu): void
    {
        $this->assertSame($attendu, EcritureDesEcarts::pourcentage($ecart, $base));
    }

    public function test_l_espace_avant_le_pourcent_est_ordinaire(): void
    {
        $this->assertSame("+1,8\u{0020}%", EcritureDesEcarts::pourcentage(18, 1000));
    }

    /**
     * Les nombres à virgule comme JavaScript les écrit : [nombre, écriture].
     *
     * @return array<string, array{0: float, 1: string}>
     */
    public static function nombres(): array
    {
        return [
            'une position du dessin' => [191.60000000000002, '191.60000000000002'],
            'une autre' => [37.666666666666664, '37.666666666666664'],
            'une position entière' => [58.0, '58'],
            'une décimale' => [147.2, '147.2'],
            'le zéro négatif' => [-0.0, '0'],
            'le zéro' => [0.0, '0'],
            'un entier rond' => [100.0, '100'],
            'une somme à virgule' => [0.1 + 0.2, '0.30000000000000004'],
            'un négatif' => [-3.5, '-3.5'],
            '1e+21 s’écrit avec exposant' => [1e21, '1e+21'],
            '1e-7 s’écrit avec exposant' => [1e-7, '1e-7'],
            '0,000001 s’écrit sans exposant' => [0.000001, '0.000001'],
            '123456789012345680000 s’écrit sans exposant' => [123456789012345680000.0, '123456789012345680000'],
            '1e20 s’écrit sans exposant' => [1e20, '100000000000000000000'],
            'le plus petit nombre positif' => [5e-324, '5e-324'],
            'le plus grand nombre' => [1.7976931348623157e308, '1.7976931348623157e+308'],
            'un exposant négatif à plusieurs chiffres' => [1.5e-10, '1.5e-10'],
            'un négatif avec exposant' => [-3.5e25, '-3.5e+25'],
            'pas un nombre' => [NAN, 'NaN'],
            'l’infini' => [INF, 'Infinity'],
            'moins l’infini' => [-INF, '-Infinity'],
        ];
    }

    #[DataProvider('nombres')]
    public function test_un_nombre_a_virgule_s_ecrit_comme_javascript(float $nombre, string $attendu): void
    {
        $this->assertSame($attendu, EcritureDesEcarts::nombreCommeJavaScript($nombre));
    }

    public function test_une_graduation_s_ecrit_sans_milliers_ni_decimale(): void
    {
        $this->assertSame('+30 €', EcritureDesEcarts::graduation(30.0));
        $this->assertSame(self::MOINS.'20 €', EcritureDesEcarts::graduation(-20.0));
        $this->assertSame('0 €', EcritureDesEcarts::graduation(0.0));
        $this->assertSame('0 €', EcritureDesEcarts::graduation(-0.0));
        $this->assertSame('+1000 €', EcritureDesEcarts::graduation(1000.0));
        $this->assertSame(self::MOINS.'20000 €', EcritureDesEcarts::graduation(-20000.0));
        $this->assertSame('+100000 €', EcritureDesEcarts::graduation(100000.0));
    }

    public function test_les_caracteres_des_formats_sont_ceux_de_la_maquette(): void
    {
        $this->assertSame('U+202F', 'U+'.strtoupper(dechex(mb_ord(self::FINE))));
        $this->assertSame('U+00A0', 'U+'.str_pad(strtoupper(dechex(mb_ord(self::INSECABLE))), 4, '0', STR_PAD_LEFT));
        $this->assertSame('U+2212', 'U+'.strtoupper(dechex(mb_ord(self::MOINS))));
        $this->assertSame("\u{2212}1,85\u{00A0}€", EcritureDesEcarts::signe(-185));
        $this->assertSame('8 × 19,54 €', '8 '."\u{00D7}".' '.EcritureDesEcarts::prix(1954));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function noms(): array
    {
        return [
            'janvier' => ['2026-01', 'janv.'],
            'février' => ['2026-02', 'févr.'],
            'mars' => ['2026-03', 'mars'],
            'avril' => ['2026-04', 'avr.'],
            'mai' => ['2026-05', 'mai'],
            'juin' => ['2026-06', 'juin'],
            'juillet' => ['2026-07', 'juil.'],
            'août' => ['2026-08', 'août'],
            'septembre' => ['2026-09', 'sept.'],
            'octobre' => ['2026-10', 'oct.'],
            'novembre' => ['2026-11', 'nov.'],
            'décembre' => ['2025-12', 'déc.'],
        ];
    }

    #[DataProvider('noms')]
    public function test_le_nom_court_d_un_mois(string $mois, string $attendu): void
    {
        $this->assertSame($attendu, Mois::nomCourt($mois));
    }

    public function test_le_nom_court_d_un_mois_illisible_est_refuse(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Mois::nomCourt('2026-13');
    }
}
