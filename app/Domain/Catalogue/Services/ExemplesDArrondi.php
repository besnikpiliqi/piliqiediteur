<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Services;

use App\Domain\Catalogue\Enums\ArrondiDuPrixDeVente;
use App\Domain\Facturation\Services\CalculateurLigne;

/**
 * Les exemples que la page « Prix de vente » montre de chaque formule
 * d'arrondi (voir ArrondiDuPrixDeVente) : la phrase sous chaque option, le
 * tableau de la formule choisie et la note grise qui en dit les règles.
 * Aucun « après » n'est écrit à la main : chacun vient du calcul même du
 * barème (BaremePrixDeVente::prixDeVente(), sans bénéfice ni TVA), pour que
 * la page ne dise jamais autre chose que ce que fait le logiciel.
 */
final class ExemplesDArrondi
{
    /**
     * Les prix du tableau, en centimes, dans l'ordre où la page les montre.
     */
    public const PRIX_DU_TABLEAU = [1840, 1850, 1855, 1100, 1250, 1700, 1750, 7760, 7400, 13440];

    /**
     * Le prix exact d'un prix HT sans TVA, en dix-millièmes d'euro : 10,495 €
     * s'écrit 10,50 € au centime, mais n'atteint pas le milieu de 10 € et de
     * 11 €.
     */
    public const PRIX_EXACT_SOUS_LE_MILIEU = 104950;

    /**
     * Le prix de vente TTC qu'une formule donne à un prix, en centimes : le
     * calcul d'un barème sans bénéfice ni TVA, c'est-à-dire le chemin de
     * tout prix de vente.
     */
    public static function prixDeVente(int $prixEnCentimes, ArrondiDuPrixDeVente $formule): int
    {
        $prix = (new BaremePrixDeVente([], 0, $formule))->prixDeVente($prixEnCentimes * 100, 0);

        return $prix === null ? $prixEnCentimes : $prix['prix_vente_ttc'];
    }

    /**
     * Les prix de la phrase d'une formule, en centimes.
     *
     * @return list<int>
     */
    private static function prixDeLaPhrase(ArrondiDuPrixDeVente $formule): array
    {
        return match ($formule) {
            ArrondiDuPrixDeVente::Aucun => [],
            ArrondiDuPrixDeVente::UnPrixEn9 => [7760, 7400, 13440],
            ArrondiDuPrixDeVente::EuroLePlusProche => [1840, 1850, 1855],
            ArrondiDuPrixDeVente::MultipleDeCinq => [1100, 1250, 1700, 1750],
        };
    }

    /**
     * La phrase sous l'option d'une formule : « 77,60 € devient 79 €,
     * 74,00 € devient 69 €, 134,40 € devient 129 €. Un prix sous 5 € reste
     * tel quel. »
     */
    public static function phrase(ArrondiDuPrixDeVente $formule): string
    {
        $calculateur = app(CalculateurLigne::class);
        $phrases = array_map(
            fn (int $prix): string => $calculateur->enEuros($prix).' devient '.self::enEurosRonds(self::prixDeVente($prix, $formule)),
            self::prixDeLaPhrase($formule),
        );

        return implode(', ', $phrases).'.'.($formule === ArrondiDuPrixDeVente::UnPrixEn9 ? ' Un prix sous 5 € reste tel quel.' : '');
    }

    /**
     * Le nom de chaque formule que la page propose, par sa valeur.
     *
     * @return array<string, string>
     */
    public static function libelles(): array
    {
        $libelles = [];

        foreach (ArrondiDuPrixDeVente::formules() as $formule) {
            $libelles[$formule->value] = $formule->libelle();
        }

        return $libelles;
    }

    /**
     * La phrase de chaque formule que la page propose, par sa valeur.
     *
     * @return array<string, string>
     */
    public static function phrases(): array
    {
        $phrases = [];

        foreach (ArrondiDuPrixDeVente::formules() as $formule) {
            $phrases[$formule->value] = self::phrase($formule);
        }

        return $phrases;
    }

    /**
     * La légende du tableau d'une formule.
     */
    public static function legende(ArrondiDuPrixDeVente $formule): string
    {
        return $formule->libelle().'. Prix de vente TTC avant et après l\'arrondi.';
    }

    /**
     * Le tableau d'une formule : pour chaque prix, son écriture avant et
     * après l'arrondi, l'écart (« – » quand le prix ne change pas, avec son
     * signe sinon) et si le prix est resté le même.
     *
     * @return list<array{avant: string, apres: string, ecart: string, inchange: bool}>
     */
    public static function tableau(ArrondiDuPrixDeVente $formule): array
    {
        $calculateur = app(CalculateurLigne::class);

        return array_map(function (int $avant) use ($calculateur, $formule): array {
            $apres = self::prixDeVente($avant, $formule);
            $ecart = $apres - $avant;

            return [
                'avant' => $calculateur->enEuros($avant),
                'apres' => $calculateur->enEuros($apres),
                'ecart' => $ecart === 0 ? '–' : ($ecart > 0 ? '+' : '').$calculateur->enEuros($ecart),
                'inchange' => $ecart === 0,
            ];
        }, self::PRIX_DU_TABLEAU);
    }

    /**
     * La note grise sous le tableau : ce que font les formules au milieu,
     * sur un prix exact et sur un prix qui deviendrait 0 €.
     */
    public static function regles(): string
    {
        $calculateur = app(CalculateurLigne::class);
        $euro = ArrondiDuPrixDeVente::EuroLePlusProche;
        $cinq = ArrondiDuPrixDeVente::MultipleDeCinq;
        $sousLeMilieu = $calculateur->prixTtcExact(self::PRIX_EXACT_SOUS_LE_MILIEU, 0);
        $sousLeMilieuAuCentime = $calculateur->prixTtcArrondi(self::PRIX_EXACT_SOUS_LE_MILIEU, 0);
        $sousLeMilieuALEuro = (new BaremePrixDeVente([], 0, $euro))->prixDeVente(self::PRIX_EXACT_SOUS_LE_MILIEU, 0)['prix_vente_ttc'] ?? $sousLeMilieuAuCentime;

        return 'Pour « '.$euro->libelle().' » et « '.$cinq->libelle().' », un prix pile au milieu monte : '
            .$calculateur->enEuros(1850).' devient '.self::enEurosRonds(self::prixDeVente(1850, $euro)).' à l\'euro près ; '
            .$calculateur->enEuros(1250).' devient '.self::enEurosRonds(self::prixDeVente(1250, $cinq))
            .' et '.$calculateur->enEuros(1750).' devient '.self::enEurosRonds(self::prixDeVente(1750, $cinq)).' au multiple de 5 €. '
            .'Le milieu se juge sur le prix exact, avant son arrondi au centime : un prix exact de '.self::enEurosExact($sousLeMilieu)
            .', qui s\'écrit '.$calculateur->enEuros($sousLeMilieuAuCentime).' au centime, n\'atteint pas le milieu et devient '.self::enEurosRonds($sousLeMilieuALEuro).' à l\'euro près. '
            .'Un prix qui deviendrait 0 € reste tel quel : '
            .$calculateur->enEuros(40).' reste '.$calculateur->enEuros(self::prixDeVente(40, $euro)).' à l\'euro près, '
            .$calculateur->enEuros(240).' reste '.$calculateur->enEuros(self::prixDeVente(240, $cinq)).' au multiple de 5 €. '
            .'Pour un prix en 9, le milieu monte aussi : '
            .$calculateur->enEuros(7500).' devient '.self::enEurosRonds(self::prixDeVente(7500, ArrondiDuPrixDeVente::UnPrixEn9)).'.';
    }

    /**
     * Un prix exact (voir CalculateurLigne::prixTtcExact()), en
     * cent-millionièmes d'euro, écrit sans zéro inutile au-delà du centime :
     * « 10,495 € », « 10,49604 € », « 12,56 € ».
     */
    public static function enEurosExact(int $exact): string
    {
        $decimales = rtrim(str_pad((string) ($exact % 100_000_000), 8, '0', STR_PAD_LEFT), '0');

        return number_format(intdiv($exact, 100_000_000), 0, ',', ' ').','.str_pad($decimales, 2, '0').' €';
    }

    /**
     * « 79 € » pour un prix rond, « 79,50 € » sinon.
     */
    private static function enEurosRonds(int $centimes): string
    {
        return $centimes % 100 === 0 ? number_format(intdiv($centimes, 100), 0, ',', ' ').' €' : app(CalculateurLigne::class)->enEuros($centimes);
    }
}
