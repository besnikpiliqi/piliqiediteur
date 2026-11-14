<?php

namespace Tests\Unit;

use App\Domain\Facturation\Services\SaisieDecimale;
use Closure;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SaisieDecimaleTest extends TestCase
{
    /**
     * Une notation scientifique se lit par son écriture décimale, arrondie
     * une fois au centième comme toute saisie.
     *
     * @return array<string, array{string, int}>
     */
    public static function notationsScientifiques(): array
    {
        return [
            'mille' => ['1e3', 100000],
            'majuscule et négatif' => ['-2.5E1', -2500],
            'demi-centième' => ['8.325e0', 833],
        ];
    }

    #[DataProvider('notationsScientifiques')]
    public function test_une_notation_scientifique_se_lit_par_son_ecriture_decimale(string $saisie, int $centiemes): void
    {
        $this->assertSame($centiemes, SaisieDecimale::enCentiemes($saisie));
    }

    /**
     * Une notation scientifique se développe avec toutes ses décimales,
     * sans arrondi préalable : « 8.3249996e0 » est sous le demi-centième
     * et vaut 832, et un montant ou un prix d'achat écrit avec une décimale
     * de trop est refusé, jamais arrondi.
     */
    public function test_une_notation_scientifique_garde_toutes_ses_decimales(): void
    {
        $this->assertSame(832, SaisieDecimale::enCentiemes('8.3249996e0'));
        $this->assertSame(123456, SaisieDecimale::enMillioniemes('1.23456e-1'));
        $this->assertThrows(fn () => SaisieDecimale::montantAuCentime('6.0000004e0'), SaisieDecimale::REFUS_MONTANT_TROP_PRECIS);
        $this->assertThrows(fn () => SaisieDecimale::enMillioniemes('1.2345674e-1'), SaisieDecimale::REFUS_PRIX_D_ACHAT_TROP_PRECIS);
    }

    /**
     * Un nombre transmis à virgule flottante se lit par son écriture la
     * plus courte, celle de la saisie transmise, puis s'arrondit une seule
     * fois : jamais d'abord à six décimales, qui feraient passer
     * 8,3249996 € au demi-centime (833) ; le bruit binaire s'efface sans
     * arrondi (8.325 vaut réellement 8,32499999999999982...).
     *
     * @return array<string, array{float, int}>
     */
    public static function flottants(): array
    {
        return [
            'sous le demi-centime' => [8.3249996, 832],
            'sous le demi-centime, plus loin' => [12.3449999, 1234],
            'sous le demi-centime, sous le centime' => [0.0049999652, 0],
            'demi-centime au bruit binaire' => [8.325, 833],
            'somme au bruit binaire' => [0.1 + 0.2, 30],
            'négatif' => [-2.5, -250],
            'entier' => [25.0, 2500],
            'en notation scientifique' => [1.5e-7, 0],
        ];
    }

    #[DataProvider('flottants')]
    public function test_un_flottant_se_lit_par_son_ecriture_la_plus_courte_et_s_arrondit_une_seule_fois(float $saisie, int $centiemes): void
    {
        $this->assertSame($centiemes, SaisieDecimale::enCentiemes($saisie));
    }

    /**
     * Un montant ou un prix d'achat transmis à virgule flottante se lit à
     * sa dernière décimale, comme écrit : une décimale de trop est refusée,
     * jamais arrondie en silence, même celle d'un calcul fait en flottant
     * (0.1 + 0.2 s'écrit 0.30000000000000004).
     */
    public function test_un_montant_et_un_prix_d_achat_en_flottant_se_lisent_a_leur_derniere_decimale(): void
    {
        $this->assertSame(650, SaisieDecimale::montantAuCentime(6.5));
        $this->assertSame(123456, SaisieDecimale::enMillioniemes(0.123456));
        $this->assertThrows(fn () => SaisieDecimale::montantAuCentime(6.005), SaisieDecimale::REFUS_MONTANT_TROP_PRECIS);
        $this->assertThrows(fn () => SaisieDecimale::montantAuCentime(6.0000001), SaisieDecimale::REFUS_MONTANT_TROP_PRECIS);
        $this->assertThrows(fn () => SaisieDecimale::montantAuCentime(0.1 + 0.2), SaisieDecimale::REFUS_MONTANT_TROP_PRECIS);
        $this->assertThrows(fn () => SaisieDecimale::enMillioniemes(0.1234567), SaisieDecimale::REFUS_PRIX_D_ACHAT_TROP_PRECIS);
    }

    /**
     * La lecture d'un flottant ne dépend d'aucun réglage de PHP : avec dix-
     * sept chiffres pour l'écriture des nombres, 8.325 vaut toujours 833 et
     * 8.3249996 832.
     */
    public function test_la_lecture_d_un_flottant_ne_depend_pas_des_reglages_de_php(): void
    {
        $reglages = ['precision' => ini_get('precision'), 'serialize_precision' => ini_get('serialize_precision')];

        try {
            ini_set('precision', '17');
            ini_set('serialize_precision', '17');

            $this->assertSame([833, 832], [SaisieDecimale::enCentiemes(8.325), SaisieDecimale::enCentiemes(8.3249996)]);
        } finally {
            foreach ($reglages as $reglage => $valeur) {
                ini_set($reglage, (string) $valeur);
            }
        }
    }

    /**
     * Un nombre trop grand pour un flottant (« 1e400 »), ou que ses
     * décimales feraient dépasser toute colonne (« 1e-50 »), n'a pas
     * d'écriture décimale lisible : il est refusé, jamais lu zéro, comme un
     * nombre au-delà de la borne du calcul.
     *
     * @return array<string, array{string}>
     */
    public static function nombresHorsLimites(): array
    {
        return [
            'infini positif' => ['1e400'],
            'infini négatif' => ['-1e400'],
            'au-delà de la borne' => ['1e20'],
            'trop de décimales' => ['1e-50'],
        ];
    }

    #[DataProvider('nombresHorsLimites')]
    public function test_un_nombre_hors_limites_est_refuse_jamais_lu_zero(string $saisie): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Montant hors limites.'));

        SaisieDecimale::enCentiemes($saisie);
    }

    #[DataProvider('nombresHorsLimites')]
    public function test_un_montant_hors_limites_est_refuse_jamais_lu_zero(string $saisie): void
    {
        $this->expectExceptionObject(new InvalidArgumentException('Montant hors limites.'));

        SaisieDecimale::montantAuCentime($saisie);
    }

    /**
     * Le plus grand montant que douze chiffres entiers écrivent reste sous
     * la borne du calcul, au centime (999 999 999 999,99 €) ; arrondi, son
     * dernier demi-centime l'y porte, et il est refusé.
     */
    public function test_la_borne_du_calcul_se_compte_apres_l_arrondi(): void
    {
        $this->assertSame(99_999_999_999_999, SaisieDecimale::montantAuCentime('999999999999.99'));
        $this->assertSame(99_999_999_999_999, SaisieDecimale::enCentiemes('999999999999.994'));
        $this->assertSame(-99_999_999_999_999, SaisieDecimale::enCentiemes('-999999999999.99'));

        $this->expectExceptionObject(new InvalidArgumentException('Montant hors limites.'));

        SaisieDecimale::enCentiemes('999999999999.995');
    }

    /**
     * Un entier compte des unités entières, une partie entière nulle ou
     * écrite avec des zéros de tête se lit, et des espaces autour d'une
     * saisie ne changent rien, même pour un prix à six décimales qu'un
     * flottant ne garderait pas exact. Une valeur qui n'est pas un nombre
     * est refusée, jamais lue zéro.
     */
    public function test_une_saisie_se_lit_exactement_quelle_que_soit_son_ecriture(): void
    {
        $this->assertSame(2500, SaisieDecimale::enCentiemes(25));
        $this->assertSame(5, SaisieDecimale::enCentiemes('0.05'));
        $this->assertSame(150, SaisieDecimale::enCentiemes('0000000000001.50'));
        $this->assertSame(123400, SaisieDecimale::enMillioniemes('0.1234'));
        $this->assertSame(123456789012345678, SaisieDecimale::enMillioniemes(' 123456789012.345678 '));
        $this->assertThrows(fn () => SaisieDecimale::enCentiemes(null), 'Nombre illisible.');
        $this->assertThrows(fn () => SaisieDecimale::enCentiemes([]), 'Nombre illisible.');
        $this->assertThrows(fn () => SaisieDecimale::enCentiemes('douze'), '« douze » n\'est pas un nombre.');
        $this->assertThrows(fn () => SaisieDecimale::montantAuCentime('12,50'), '« 12,50 » n\'est pas un nombre.');
    }

    /**
     * Un montant au centime et un prix d'achat à six décimales se lisent tels
     * qu'écrits : des zéros après leur dernière décimale ne comptent pas, un
     * chiffre de plus est refusé, jamais arrondi.
     */
    public function test_un_montant_et_un_prix_d_achat_se_lisent_a_leur_derniere_decimale(): void
    {
        $this->assertSame(650, SaisieDecimale::montantAuCentime('6.5'));
        $this->assertSame(50, SaisieDecimale::montantAuCentime('0.50'));
        $this->assertSame(605, SaisieDecimale::montantAuCentime('6.050'));
        $this->assertSame(600, SaisieDecimale::montantAuCentime('6.000'));
        $this->assertSame(123456, SaisieDecimale::enMillioniemes('0.1234560'));
        $this->assertThrows(fn () => SaisieDecimale::montantAuCentime('6.005'), SaisieDecimale::REFUS_MONTANT_TROP_PRECIS);
        $this->assertThrows(fn () => SaisieDecimale::enMillioniemes('0.1234567'), SaisieDecimale::REFUS_PRIX_D_ACHAT_TROP_PRECIS);
    }

    private function assertThrows(Closure $lecture, string $message): void
    {
        try {
            $lecture();
        } catch (InvalidArgumentException $refus) {
            $this->assertSame($message, $refus->getMessage());

            return;
        }

        $this->fail("La saisie devait être refusée : {$message}");
    }
}
