<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Enums\PrixReference;

/**
 * Calcul HT/TVA/TTC d'une ligne de document, à partir du prix réellement
 * saisi sur la ligne (voir PrixReference), quel que soit le document ou le
 * client -- jamais en reconstruisant ce prix à partir de l'autre puis en le
 * multipliant par la quantité :
 *
 * - PU TTC saisi : total TTC = PU TTC x quantité, puis le HT est déduit
 *   UNE seule fois de ce total (calculerDepuisTtc()). 40 € TTC x 2 donne
 *   toujours 80,00 €, là où passer par 33,33 € HT x 2 x 1,20 donnait
 *   79,99 €.
 * - PU HT saisi : total HT = PU HT x quantité, puis la TVA est calculée sur
 *   ce total (calculerDepuisHt()). 8,33 € HT x 3 donne toujours 24,99 € HT
 *   et la TVA exacte qu'un professionnel récupère, là où passer par un PU
 *   TTC arrondi (10,00 €) donnait 25,00 € HT.
 *
 * Méthode "ligne à ligne" : chaque ligne est arrondie au centime, puis les
 * totaux du document somment les lignes déjà arrondies (le total d'une
 * facture est toujours la somme visible de ses lignes).
 *
 * Tout le calcul se fait en entiers (centimes, millièmes de quantité,
 * centièmes de point de taux), jamais en flottant intermédiaire : un
 * flottant comme 8.325 vaut réellement ~8.32499999999999982..., et un seul
 * centime d'écart avec le montant transmis à l'administration (API PDP)
 * suffit à faire rejeter une facture.
 */
class CalculateurLigne
{
    /**
     * @return array{montant_ht: float, montant_tva: float, montant_ttc: float}
     */
    public function calculer(
        PrixReference $reference,
        float $prixUnitaireHt,
        float $prixUnitaireTtc,
        float $quantite,
        float $tauxTva,
        float $remisePourcentage = 0.0,
    ): array {
        return match ($reference) {
            PrixReference::Ttc => $this->calculerDepuisTtc($prixUnitaireTtc, $quantite, $tauxTva, $remisePourcentage),
            PrixReference::Ht => $this->calculerDepuisHt($prixUnitaireHt, $quantite, $tauxTva, $remisePourcentage),
        };
    }

    /**
     * Le prix de référence au centime, et l'autre prix qui en découle.
     *
     * @return array{prix_unitaire_ht: float, prix_unitaire_ttc: float}
     */
    public function prixUnitaires(PrixReference $reference, float $prixDeReference, float $tauxTva): array
    {
        $prixDeReference = $this->arrondirAuCentime($prixDeReference);

        return match ($reference) {
            PrixReference::Ttc => [
                'prix_unitaire_ht' => $this->prixUnitaireHtDepuisTtc($prixDeReference, $tauxTva),
                'prix_unitaire_ttc' => $prixDeReference,
            ],
            PrixReference::Ht => [
                'prix_unitaire_ht' => $prixDeReference,
                'prix_unitaire_ttc' => $this->prixUnitaireTtcDepuisHt($prixDeReference, $tauxTva),
            ],
        };
    }

    /**
     * Montants du dernier retour d'une ligne : ce qui reste de la ligne une
     * fois déduits les retours déjà émis, plutôt qu'un recalcul de la
     * quantité restante. Chaque retour partiel est arrondi au centime, donc
     * les recalculer tous ferait créditer au total un centime de HT ou de
     * TVA de plus ou de moins que la ligne facturée ; le solde garantit que
     * HT, TVA et TTC crédités retombent exactement sur la ligne.
     *
     * @param  array{montant_ht: float|string, montant_tva: float|string, montant_ttc: float|string}  $montantsLigne
     * @param  iterable<array{montant_ht: float|string, montant_tva: float|string, montant_ttc: float|string}>  $montantsDejaCredites
     * @return array{montant_ht: float, montant_tva: float, montant_ttc: float}
     */
    public function solder(array $montantsLigne, iterable $montantsDejaCredites): array
    {
        $dejaCredite = $this->totaliser($montantsDejaCredites);

        return [
            'montant_ht' => $this->centimesVersMontant($this->versUnites((float) $montantsLigne['montant_ht'], 100) - $this->versUnites($dejaCredite['total_ht'], 100)),
            'montant_tva' => $this->centimesVersMontant($this->versUnites((float) $montantsLigne['montant_tva'], 100) - $this->versUnites($dejaCredite['total_tva'], 100)),
            'montant_ttc' => $this->centimesVersMontant($this->versUnites((float) $montantsLigne['montant_ttc'], 100) - $this->versUnites($dejaCredite['total_ttc'], 100)),
        ];
    }

    /**
     * @return array{montant_ht: float, montant_tva: float, montant_ttc: float}
     */
    public function calculerDepuisTtc(
        float $prixUnitaireTtc,
        float $quantite,
        float $tauxTva,
        float $remisePourcentage = 0.0,
    ): array {
        $montantTtcCentimes = $this->montantNetCentimes($prixUnitaireTtc, $quantite, $remisePourcentage);
        $montantHtCentimes = $this->htDepuisTtcCentimes($montantTtcCentimes, $tauxTva);

        return [
            'montant_ht' => $this->centimesVersMontant($montantHtCentimes),
            'montant_tva' => $this->centimesVersMontant($montantTtcCentimes - $montantHtCentimes),
            'montant_ttc' => $this->centimesVersMontant($montantTtcCentimes),
        ];
    }

    /**
     * @return array{montant_ht: float, montant_tva: float, montant_ttc: float}
     */
    public function calculerDepuisHt(
        float $prixUnitaireHt,
        float $quantite,
        float $tauxTva,
        float $remisePourcentage = 0.0,
    ): array {
        $montantHtCentimes = $this->montantNetCentimes($prixUnitaireHt, $quantite, $remisePourcentage);
        $montantTvaCentimes = $this->diviserArrondi($montantHtCentimes * $this->versUnites($tauxTva, 100), 10000);

        return [
            'montant_ht' => $this->centimesVersMontant($montantHtCentimes),
            'montant_tva' => $this->centimesVersMontant($montantTvaCentimes),
            'montant_ttc' => $this->centimesVersMontant($montantHtCentimes + $montantTvaCentimes),
        ];
    }

    /**
     * PU HT affiché (mention légale) d'une ligne de vente dont le PU TTC
     * fait référence : jamais réutilisé dans un calcul de montant.
     */
    public function prixUnitaireHtDepuisTtc(float $prixUnitaireTtc, float $tauxTva): float
    {
        return $this->centimesVersMontant($this->htDepuisTtcCentimes($this->versUnites($prixUnitaireTtc, 100), $tauxTva));
    }

    public function prixUnitaireTtcDepuisHt(float $prixUnitaireHt, float $tauxTva): float
    {
        $htCentimes = $this->versUnites($prixUnitaireHt, 100);

        return $this->centimesVersMontant($htCentimes + $this->diviserArrondi($htCentimes * $this->versUnites($tauxTva, 100), 10000));
    }

    /**
     * Ramène un prix saisi au centime, exactement comme il sera enregistré
     * (colonnes decimal(12,2)) : le calcul doit porter sur la valeur
     * stockée, jamais sur une saisie plus précise que ce qui est conservé.
     */
    public function arrondirAuCentime(float $montant): float
    {
        return $this->centimesVersMontant($this->versUnites($montant, 100));
    }

    /**
     * @param  iterable<array{montant_ht: float|string, montant_tva: float|string, montant_ttc: float|string}>  $lignes
     * @return array{total_ht: float, total_tva: float, total_ttc: float}
     */
    public function totaliser(iterable $lignes): array
    {
        $totalHtCentimes = 0;
        $totalTvaCentimes = 0;
        $totalTtcCentimes = 0;

        foreach ($lignes as $ligne) {
            $totalHtCentimes += $this->versUnites((float) $ligne['montant_ht'], 100);
            $totalTvaCentimes += $this->versUnites((float) $ligne['montant_tva'], 100);
            $totalTtcCentimes += $this->versUnites((float) $ligne['montant_ttc'], 100);
        }

        return [
            'total_ht' => $this->centimesVersMontant($totalHtCentimes),
            'total_tva' => $this->centimesVersMontant($totalTvaCentimes),
            'total_ttc' => $this->centimesVersMontant($totalTtcCentimes),
        ];
    }

    /**
     * Prix unitaire x quantité, remise déduite, en centimes : identique en
     * HT comme en TTC, seul le sens de la TVA change ensuite.
     */
    private function montantNetCentimes(float $prixUnitaire, float $quantite, float $remisePourcentage): int
    {
        $montantBrutCentimes = $this->diviserArrondi(
            $this->versUnites($prixUnitaire, 100) * $this->versUnites($quantite, 1000),
            1000,
        );

        return $montantBrutCentimes - $this->diviserArrondi($montantBrutCentimes * $this->versUnites($remisePourcentage, 100), 10000);
    }

    private function htDepuisTtcCentimes(int $ttcCentimes, float $tauxTva): int
    {
        return $this->diviserArrondi($ttcCentimes * 10000, 10000 + $this->versUnites($tauxTva, 100));
    }

    /**
     * Le pré-arrondi à 6 décimales efface le bruit binaire du flottant
     * (8.325 * 100 = 832.4999999999999) avant l'arrondi final, sans
     * dépendre de la façon dont round() traite lui-même ce cas limite.
     */
    private function versUnites(float $valeur, int $facteur): int
    {
        return (int) round(round($valeur * $facteur, 6));
    }

    /**
     * Division entière arrondie au plus proche (demi vers l'extérieur),
     * sans jamais passer par un flottant.
     */
    private function diviserArrondi(int $numerateur, int $denominateur): int
    {
        $quotient = intdiv($numerateur, $denominateur);

        if (2 * abs($numerateur % $denominateur) >= $denominateur) {
            $quotient += $numerateur < 0 ? -1 : 1;
        }

        return $quotient;
    }

    private function centimesVersMontant(int $centimes): float
    {
        return $centimes / 100;
    }
}
