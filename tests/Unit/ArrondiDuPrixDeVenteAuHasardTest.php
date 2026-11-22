<?php

namespace Tests\Unit;

use App\Domain\Catalogue\Enums\ArrondiDuPrixDeVente;
use App\Domain\Catalogue\Services\BaremePrixDeVente;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\TestCase;

/**
 * Les trois formules d'arrondi du prix de vente (voir
 * ArrondiDuPrixDeVenteTest) comparées, sur 300 prix tirés au hasard chacune,
 * à un calcul indépendant en centimes entiers, qui n'utilise ni
 * CalculateurLigne ni l'énumération. Le hasard est tiré d'une graine fixe
 * (GRAINE) : un échec se rejoue toujours à l'identique, et son message donne
 * le cas fautif.
 */
class ArrondiDuPrixDeVenteAuHasardTest extends TestCase
{
    private const GRAINE = 20261122;

    private const TIRAGES = 300;

    /**
     * Taux de TVA français, en centièmes de point.
     */
    private const TAUX = [0, 210, 550, 850, 1000, 2000];

    /**
     * @return array<string, array{ArrondiDuPrixDeVente, int}>
     */
    public static function formules(): array
    {
        return [
            'un prix en 9' => [ArrondiDuPrixDeVente::UnPrixEn9, 1000],
            'l\'euro le plus proche' => [ArrondiDuPrixDeVente::EuroLePlusProche, 100],
            'le multiple de 5 € le plus proche' => [ArrondiDuPrixDeVente::MultipleDeCinq, 500],
        ];
    }

    /**
     * Un tirage sur trois est un prix exact pile au milieu de deux multiples
     * (taux 0, prix HT en dix-millièmes), un sur trois à un dix-millième de
     * ce milieu, de part et d'autre.
     */
    #[DataProvider('formules')]
    public function test_chaque_formule_sur_300_prix_tires_au_hasard_contre_un_calcul_independant(ArrondiDuPrixDeVente $formule, int $pas): void
    {
        $hasard = new Randomizer(new Mt19937(self::GRAINE + $pas));

        for ($tirage = 0; $tirage < self::TIRAGES; $tirage++) {
            $taux = self::TAUX[$hasard->getInt(0, count(self::TAUX) - 1)];
            $benefice = $hasard->getInt(0, 5000);
            $achat = $hasard->getInt(1, 5_000_000);

            if ($tirage % 3 === 1) {
                // Le milieu exact : (k + 1/2) x pas, en dix-millièmes, sans TVA ni bénéfice.
                $achat = ($hasard->getInt(0, 400) * $pas + intdiv($pas, 2)) * 100;
                $taux = 0;
                $benefice = 0;
            } elseif ($tirage % 3 === 2) {
                $achat = max(1, ($hasard->getInt(0, 400) * $pas + intdiv($pas, 2)) * 100 + $hasard->getInt(-1, 1));
                $taux = 0;
                $benefice = 0;
            }

            $cas = "formule {$formule->value}, achat {$achat}, bénéfice {$benefice}, taux {$taux}";
            $exact = ($achat + $benefice * 100) * (10000 + $taux);
            $cible = intdiv($exact, 1_000_000) + (2 * ($exact % 1_000_000) >= 1_000_000 ? 1 : 0);
            $unite = $pas * 1_000_000;
            $multiple = (intdiv($exact, $unite) + (2 * ($exact % $unite) >= $unite ? 1 : 0)) * $pas;
            $attendu = match ($formule) {
                ArrondiDuPrixDeVente::UnPrixEn9 => $multiple >= 1000 ? $multiple - 100 : $cible,
                default => $multiple > 0 ? $multiple : $cible,
            };
            $laisseTelQuel = $attendu === $cible && ($formule === ArrondiDuPrixDeVente::UnPrixEn9 ? $multiple < 1000 : $multiple <= 0);

            $prix = (new BaremePrixDeVente([], $benefice, $formule))->prixDeVente($achat, $taux);

            $this->assertSame($attendu, $prix['prix_vente_ttc'], $cas);
            $this->assertSame($cible, $prix['prix_cible_ttc'], $cas);
            $this->assertSame($laisseTelQuel, $prix['laisse_tel_quel'], $cas);

            $this->assertTrue(
                $prix['laisse_tel_quel'] || match ($formule) {
                    ArrondiDuPrixDeVente::UnPrixEn9 => $prix['prix_vente_ttc'] % 1000 === 900,
                    default => $prix['prix_vente_ttc'] % $pas === 0,
                },
                "Le prix arrondi n'a pas la forme de la formule : {$cas}.",
            );
            // L'écart au prix exact ne dépasse jamais la moitié du pas, plus
            // le centime de l'arrondi du prix cible (et le euro du « moins
            // 1 € » d'un prix en 9).
            $ecart = abs($prix['prix_vente_ttc'] * 1_000_000 - $exact);
            $limite = intdiv($pas, 2) * 1_000_000 + 1_000_000 + ($formule === ArrondiDuPrixDeVente::UnPrixEn9 ? 100_000_000 : 0);
            $this->assertLessThanOrEqual($limite, $ecart, $cas);
        }
    }
}
