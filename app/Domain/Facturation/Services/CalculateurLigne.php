<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Enums\PrixReference;
use InvalidArgumentException;

/**
 * Calcul HT/TVA/TTC d'une ligne de document, article par article :
 *
 * 1. Chaque article a son PU HT, sa TVA et son PU TTC, au centime, avec
 *    PU HT + TVA = PU TTC (voir prixUnitaires()) : le prix réellement saisi
 *    (HT ou TTC, voir PrixReference) est gardé tel quel, l'autre en est la
 *    conversion au centime. 40,00 € TTC = 33,33 € HT + 6,67 € de TVA ;
 *    8,33 € HT = 8,33 € HT + 1,67 € de TVA = 10,00 € TTC.
 * 2. La ligne multiplie séparément le HT et le TTC par la quantité, et sa
 *    TVA est leur différence (calculer()) : 2 x 40,00 € = 66,66 € HT +
 *    13,34 € de TVA = 80,00 € TTC. Chaque montant affiché se vérifie donc
 *    à la main (PU x quantité), et HT + TVA = TTC sur chaque ligne.
 * 3. Les totaux du document somment séparément les HT, les TVA et les TTC
 *    des lignes (totaliser()).
 *
 * Tout le calcul se fait en entiers (centimes, nombre d'articles,
 * centièmes de point de taux et de remise), jamais en flottant
 * intermédiaire. Un flottant comme 8.325 vaut réellement
 * ~8.32499999999999982..., et un seul centime d'écart avec le montant
 * transmis à l'administration (API PDP) suffit à faire rejeter une
 * facture.
 */
class CalculateurLigne
{
    /**
     * Bien au-delà de tout montant réel (les colonnes s'arrêtent à
     * 9 999 999 999,99 €, soit 1e12 centimes) : un montant absurde est
     * refusé plutôt que converti.
     */
    private const UNITES_MAXIMUM = 1e14;

    /**
     * @param  int  $quantite  nombre entier d'articles (voir QuantiteEntiere)
     * @return array{montant_ht: float, montant_tva: float, montant_ttc: float}
     *
     * @throws InvalidArgumentException
     */
    public function calculer(
        float $prixUnitaireHt,
        float $prixUnitaireTtc,
        int $quantite,
        float $remisePourcentage = 0.0,
    ): array {
        if ($remisePourcentage < 0 || $remisePourcentage > 100) {
            throw new InvalidArgumentException('La remise doit être comprise entre 0 et 100 %.');
        }

        $remiseCentiemes = $this->versUnites($remisePourcentage, 100);

        $montantHtCentimes = $this->montantNetCentimes($this->versUnites($prixUnitaireHt, 100), $quantite, $remiseCentiemes);
        $montantTtcCentimes = $this->montantNetCentimes($this->versUnites($prixUnitaireTtc, 100), $quantite, $remiseCentiemes);

        return [
            'montant_ht' => $this->centimesVersMontant($montantHtCentimes),
            'montant_tva' => $this->centimesVersMontant($montantTtcCentimes - $montantHtCentimes),
            'montant_ttc' => $this->centimesVersMontant($montantTtcCentimes),
        ];
    }

    /**
     * Le prix saisi au centime, et l'autre prix qui en découle -- la TVA
     * unitaire étant toujours leur différence exacte.
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

    public function prixUnitaireHtDepuisTtc(float $prixUnitaireTtc, float $tauxTva): float
    {
        $this->verifierTauxTva($tauxTva);

        return $this->centimesVersMontant($this->diviserArrondi(
            $this->multiplier($this->versUnites($prixUnitaireTtc, 100), 10000),
            10000 + $this->versUnites($tauxTva, 100),
        ));
    }

    public function prixUnitaireTtcDepuisHt(float $prixUnitaireHt, float $tauxTva): float
    {
        $this->verifierTauxTva($tauxTva);

        $htCentimes = $this->versUnites($prixUnitaireHt, 100);

        return $this->centimesVersMontant($htCentimes + $this->diviserArrondi($this->multiplier($htCentimes, $this->versUnites($tauxTva, 100)), 10000));
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
     * Valeur d'un stock au coût moyen (voir coutMoyenPondere()) : la
     * quantité x le CMUP au dix-millième, arrondie une seule fois au
     * centime -- 2 000 articles à 0,045 € valent 90,00 € HT, pas 2 000 x
     * 0,05 €. La TVA se calcule sur ce montant HT.
     *
     * @return array{montant_ht: float, montant_tva: float, montant_ttc: float}
     */
    public function valeurAuCoutMoyen(int $quantite, float $coutMoyenHt, float $tauxTva): array
    {
        $this->verifierTauxTva($tauxTva);

        $htCentimes = $this->diviserArrondi($this->multiplier($this->versUnites($coutMoyenHt, 10000), $quantite), 100);
        $tvaCentimes = $this->diviserArrondi($this->multiplier($htCentimes, $this->versUnites($tauxTva, 100)), 10000);

        return [
            'montant_ht' => $this->centimesVersMontant($htCentimes),
            'montant_tva' => $this->centimesVersMontant($tvaCentimes),
            'montant_ttc' => $this->centimesVersMontant($htCentimes + $tvaCentimes),
        ];
    }

    /**
     * Un coût moyen (voir coutMoyenPondere()) tel qu'il est enregistré,
     * au dix-millième d'euro.
     */
    public function arrondirAuDixMillieme(float $montant): float
    {
        return $this->versUnites($montant, 10000) / 10000;
    }

    /**
     * Coût unitaire moyen de lots (quantité, coût unitaire) pondéré par
     * leurs quantités, au dix-millième d'euro : Σ(quantité x coût) /
     * Σ quantité. Une quantité négative retire un lot (voir
     * StockLedgerService::corrigerUneReception()). Null quand les lots ne
     * pèsent rien : quantité totale ou valeur totale nulle ou négative.
     *
     * @param  iterable<array{quantite: int, cout_unitaire: float|string}>  $lots
     */
    public function coutMoyenPondere(iterable $lots): ?float
    {
        $quantiteTotale = 0;
        $valeurTotaleDixMilliemes = 0;

        foreach ($lots as $lot) {
            $quantiteTotale += $lot['quantite'];
            $valeurTotaleDixMilliemes += $this->multiplier($this->versUnites((float) $lot['cout_unitaire'], 10000), $lot['quantite']);
        }

        if ($quantiteTotale <= 0 || $valeurTotaleDixMilliemes <= 0) {
            return null;
        }

        return $this->diviserArrondi($valeurTotaleDixMilliemes, $quantiteTotale) / 10000;
    }

    /**
     * Montants du dernier retour d'une ligne : ce qui reste de la ligne une
     * fois déduits les retours déjà émis, plutôt qu'un recalcul de la
     * quantité restante. Avec une remise, recalculer chaque retour pourrait
     * créditer au total un centime de plus ou de moins que la ligne
     * facturée (2 x 8,33 € HT remisés de 10 % = 14,99 € HT, mais chaque
     * article rendu seul vaut 7,50 € HT) ; le solde garantit que HT, TVA et
     * TTC crédités retombent exactement sur la ligne (7,50 € puis 7,49 €).
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
     * Cumul de documents déjà totalisés (factures d'une clôture de caisse,
     * clôtures d'une période d'e-reporting) : les mêmes sommes séparées des
     * HT, des TVA et des TTC qu'entre les lignes d'un document.
     *
     * @param  iterable<array{total_ht: float|string, total_tva: float|string, total_ttc: float|string}|ArrayAccess<string, mixed>>  $documents
     * @return array{total_ht: float, total_tva: float, total_ttc: float}
     */
    public function cumuler(iterable $documents): array
    {
        $totaux = [];

        foreach ($documents as $document) {
            $totaux[] = [
                'montant_ht' => $document['total_ht'],
                'montant_tva' => $document['total_tva'],
                'montant_ttc' => $document['total_ttc'],
            ];
        }

        return $this->totaliser($totaux);
    }

    /**
     * Un montant au centime moins une remise en pourcentage (remise d'une
     * ligne, escompte d'une facture), arrondie au centime le plus proche
     * comme dans calculer().
     *
     * @throws InvalidArgumentException
     */
    public function appliquerRemise(float $montant, float $remisePourcentage): float
    {
        if ($remisePourcentage < 0 || $remisePourcentage > 100) {
            throw new InvalidArgumentException('La remise doit être comprise entre 0 et 100 %.');
        }

        return $this->centimesVersMontant(
            $this->montantNetCentimes($this->versUnites($montant, 100), 1, $this->versUnites($remisePourcentage, 100)),
        );
    }

    /**
     * Des totaux moins ceux des documents qui les corrigent (les ventes
     * d'une journée de caisse moins ses avoirs), au centime, séparément
     * pour les HT, les TVA et les TTC.
     *
     * @param  array{total_ht: float, total_tva: float, total_ttc: float}  $totaux
     * @param  array{total_ht: float, total_tva: float, total_ttc: float}  $totauxDeduits
     * @return array{total_ht: float, total_tva: float, total_ttc: float}
     */
    public function deduire(array $totaux, array $totauxDeduits): array
    {
        return [
            'total_ht' => $this->soustraire($totaux['total_ht'], $totauxDeduits['total_ht']),
            'total_tva' => $this->soustraire($totaux['total_tva'], $totauxDeduits['total_tva']),
            'total_ttc' => $this->soustraire($totaux['total_ttc'], $totauxDeduits['total_ttc']),
        ];
    }

    /**
     * Somme exacte de montants au centime (paiements, crédits d'avoir...),
     * en centimes -- jamais une somme de flottants.
     *
     * @param  iterable<float|int|string|null>  $montants
     */
    public function sommer(iterable $montants): float
    {
        $totalCentimes = 0;

        foreach ($montants as $montant) {
            $totalCentimes += $this->versUnites((float) $montant, 100);
        }

        return $this->centimesVersMontant($totalCentimes);
    }

    /**
     * Un montant moins ceux qui en sont déduits, au centime exact (reste à
     * payer, crédit encore disponible, monnaie à rendre).
     */
    public function soustraire(float|int|string|null $montant, float|int|string|null ...$montantsDeduits): float
    {
        return $this->centimesVersMontant(
            $this->versUnites((float) $montant, 100) - $this->versUnites($this->sommer($montantsDeduits), 100),
        );
    }

    /**
     * Un taux hors de 0-100 % n'existe pas -- et -100 % diviserait par
     * zéro en passant du TTC au HT.
     */
    private function verifierTauxTva(float $tauxTva): void
    {
        if ($tauxTva < 0 || $tauxTva > 100) {
            throw new InvalidArgumentException('Le taux de TVA doit être compris entre 0 et 100 %.');
        }
    }

    /**
     * Prix unitaire x quantité, remise déduite, en centimes : le montant
     * brut tombe toujours pile au centime, seule la remise s'arrondit.
     */
    private function montantNetCentimes(int $prixUnitaireCentimes, int $quantite, int $remiseCentiemes): int
    {
        $montantBrutCentimes = $this->multiplier($prixUnitaireCentimes, $quantite);

        if (abs($montantBrutCentimes) >= self::UNITES_MAXIMUM) {
            throw new InvalidArgumentException('Montant hors limites.');
        }

        return $montantBrutCentimes - $this->diviserArrondi($this->multiplier($montantBrutCentimes, $remiseCentiemes), 10000);
    }

    /**
     * Produit d'entiers : en PHP, un produit qui déborde devient un
     * flottant (approché) au lieu d'échouer -- il est refusé.
     */
    private function multiplier(int $a, int $b): int
    {
        $produit = $a * $b;

        if (! is_int($produit)) {
            throw new InvalidArgumentException('Montant hors limites.');
        }

        return $produit;
    }

    /**
     * Le pré-arrondi à 6 décimales efface le bruit binaire du flottant
     * (8.325 * 100 = 832.4999999999999) avant l'arrondi final, sans
     * dépendre de la façon dont round() traite lui-même ce cas limite.
     *
     * Un montant absurde (des milliards de milliards) est refusé plutôt
     * que converti : au-delà, l'entier déborderait en silence (PHP 8.3) ou
     * lèverait une erreur (PHP 8.5).
     */
    private function versUnites(float $valeur, int $facteur): int
    {
        $unites = round(round($valeur * $facteur, 6));

        if (! is_finite($unites) || abs($unites) >= self::UNITES_MAXIMUM) {
            throw new InvalidArgumentException('Montant hors limites.');
        }

        return (int) $unites;
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
