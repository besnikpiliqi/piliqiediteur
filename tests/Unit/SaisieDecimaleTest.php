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
     * Un nombre trop grand pour un flottant (« 1e400 ») n'a pas d'écriture
     * décimale : il est refusé, jamais lu zéro, comme un nombre au-delà de
     * la borne du calcul.
     *
     * @return array<string, array{string}>
     */
    public static function nombresHorsLimites(): array
    {
        return [
            'infini positif' => ['1e400'],
            'infini négatif' => ['-1e400'],
            'au-delà de la borne' => ['1e20'],
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
