<?php

namespace App\Domain\Facturation\Services;

/**
 * Calcul HT/TVA/TTC d'une ligne de document (devis ou facture).
 *
 * Méthode "ligne à ligne" : chaque ligne est arrondie à 2 décimales
 * indépendamment, puis les totaux du document somment les lignes déjà
 * arrondies. C'est la méthode la plus répandue et la plus simple à auditer
 * (le total d'une facture est toujours la somme visible de ses lignes).
 * L'alternative ("méthode globale", agrégation par taux de TVA avant
 * arrondi) est également admise par l'administration fiscale française,
 * mais n'est pas implémentée ici -- à ajouter si un client l'exige
 * explicitement pour des raisons de rapprochement comptable.
 */
class CalculateurLigne
{
    /**
     * @return array{montant_ht: float, montant_tva: float, montant_ttc: float}
     */
    public function calculer(
        float $prixUnitaireHt,
        float $quantite,
        float $tauxTva,
        float $remisePourcentage = 0.0,
    ): array {
        $montantBrutHt = $prixUnitaireHt * $quantite;
        $montantRemise = $montantBrutHt * ($remisePourcentage / 100);
        $montantHt = round($montantBrutHt - $montantRemise, 2);
        $montantTva = round($montantHt * ($tauxTva / 100), 2);

        return [
            'montant_ht' => $montantHt,
            'montant_tva' => $montantTva,
            'montant_ttc' => round($montantHt + $montantTva, 2),
        ];
    }

    /**
     * @param  iterable<array{montant_ht: float|string, montant_tva: float|string, montant_ttc: float|string}>  $lignes
     * @return array{total_ht: float, total_tva: float, total_ttc: float}
     */
    public function totaliser(iterable $lignes): array
    {
        $totalHt = 0.0;
        $totalTva = 0.0;
        $totalTtc = 0.0;

        foreach ($lignes as $ligne) {
            $totalHt += (float) $ligne['montant_ht'];
            $totalTva += (float) $ligne['montant_tva'];
            $totalTtc += (float) $ligne['montant_ttc'];
        }

        return [
            'total_ht' => round($totalHt, 2),
            'total_tva' => round($totalTva, 2),
            'total_ttc' => round($totalTtc, 2),
        ];
    }
}
