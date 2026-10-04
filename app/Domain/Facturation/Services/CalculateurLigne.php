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
 *
 * Tout le calcul se fait en centimes ENTIERS, jamais en flottant
 * intermédiaire : un flottant comme 8.325 n'a pas de représentation binaire
 * exacte (il vaut réellement ~8.32499999999999982...), donc round(8.325, 2)
 * peut arrondir vers le mauvais côté selon la valeur exacte représentée.
 * En décimal/finance, ce risque est inacceptable : une différence d'un seul
 * centime entre le montant affiché/stocké et celui réellement transmis à
 * l'administration (API PDP) suffit à faire rejeter la facture. On convertit
 * donc le prix unitaire en centimes dès l'entrée, on arrondit à chaque étape
 * sur des entiers (jamais de perte "silencieuse" accumulée), et on ne
 * reconvertit en flottant qu'à la toute fin, pour le stockage/affichage.
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
        $prixUnitaireCentimes = (int) round($prixUnitaireHt * 100);

        $montantBrutCentimes = (int) round($prixUnitaireCentimes * $quantite);
        $remiseCentimes = (int) round($montantBrutCentimes * ($remisePourcentage / 100));
        $montantHtCentimes = $montantBrutCentimes - $remiseCentimes;
        $montantTvaCentimes = (int) round($montantHtCentimes * ($tauxTva / 100));

        return [
            'montant_ht' => $this->centimesVersMontant($montantHtCentimes),
            'montant_tva' => $this->centimesVersMontant($montantTvaCentimes),
            'montant_ttc' => $this->centimesVersMontant($montantHtCentimes + $montantTvaCentimes),
        ];
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
            // Les montants de ligne sont déjà arrondis à 2 décimales (ils
            // sortent de calculer() ci-dessus) : la seule conversion
            // flottant -> entier restante porte sur une valeur déjà
            // "ronde", jamais sur un calcul intermédiaire.
            $totalHtCentimes += (int) round(((float) $ligne['montant_ht']) * 100);
            $totalTvaCentimes += (int) round(((float) $ligne['montant_tva']) * 100);
            $totalTtcCentimes += (int) round(((float) $ligne['montant_ttc']) * 100);
        }

        return [
            'total_ht' => $this->centimesVersMontant($totalHtCentimes),
            'total_tva' => $this->centimesVersMontant($totalTvaCentimes),
            'total_ttc' => $this->centimesVersMontant($totalTtcCentimes),
        ];
    }

    private function centimesVersMontant(int $centimes): float
    {
        return $centimes / 100;
    }
}
