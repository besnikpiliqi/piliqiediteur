<?php

namespace App\Domain\Facturation\Services;

use InvalidArgumentException;

/**
 * Calcul HT/TVA/TTC d'une ligne de document. Le seul prix arrondi est le
 * prix de vente TTC, une fois, quand il est enregistré ; ensuite rien ne
 * s'arrondit qu'au moment de calculer un montant.
 *
 * 1. Une vente part de son prix TTC, décomposé article par article :
 *    chaque article a son PU HT (la conversion du TTC au centime, comme
 *    sur la fiche du produit), sa TVA et son PU TTC, avec PU HT + TVA =
 *    PU TTC ; la ligne multiplie séparément le HT et le TTC par la
 *    quantité, sa TVA étant leur différence (montantsDeVente()) : 2 x
 *    40,00 € TTC = 66,66 € HT + 13,34 € de TVA = 80,00 € TTC.
 * 2. Un achat part du PU HT du fournisseur, multiplié par la quantité,
 *    puis la TVA se calcule une seule fois sur le HT de la ligne
 *    (montantsDAchat()) : 10 x 1,18 € HT = 11,80 € HT + 2,36 € de TVA
 *    (20 % de 11,80 €) = 14,16 € TTC -- jamais 10 x 1,42 € (1,18 € x 1,2
 *    = 1,416 € arrondi) = 14,20 €.
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
     * Une ligne de vente, article par article depuis son PU TTC (voir le
     * point 1 du commentaire de classe).
     *
     * @param  int  $quantite  nombre entier d'articles (voir QuantiteEntiere)
     * @return array{montant_ht: float, montant_tva: float, montant_ttc: float}
     *
     * @throws InvalidArgumentException
     */
    public function montantsDeVente(float $prixUnitaireHt, float $prixUnitaireTtc, int $quantite, float $remisePourcentage = 0.0): array
    {
        $remiseCentiemes = $this->remiseEnCentiemes($remisePourcentage);

        return $this->montants(
            $this->montantNetCentimes($this->versUnites($prixUnitaireHt, 100), $quantite, $remiseCentiemes),
            $this->montantNetCentimes($this->versUnites($prixUnitaireTtc, 100), $quantite, $remiseCentiemes),
        );
    }

    /**
     * Une ligne d'achat, depuis le PU HT du fournisseur : la TVA se calcule
     * une seule fois sur le HT de la ligne (voir le point 2 du commentaire
     * de classe).
     *
     * @param  int  $quantite  nombre entier d'articles (voir QuantiteEntiere)
     * @return array{montant_ht: float, montant_tva: float, montant_ttc: float}
     *
     * @throws InvalidArgumentException
     */
    public function montantsDAchat(float $prixUnitaireHt, float $tauxTva, int $quantite, float $remisePourcentage = 0.0): array
    {
        $this->verifierTauxTva($tauxTva);

        $montantHtCentimes = $this->montantNetCentimes($this->versUnites($prixUnitaireHt, 100), $quantite, $this->remiseEnCentiemes($remisePourcentage));

        return $this->montants(
            $montantHtCentimes,
            $montantHtCentimes + $this->diviserArrondi($this->multiplier($montantHtCentimes, $this->versUnites($tauxTva, 100)), 10000),
        );
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
     * Le prix de vente TTC d'un prix HT exact -- un coût d'achat au
     * dix-millième plus un bénéfice (voir BaremePrixDeVente) : HT x (1 +
     * TVA), arrondi une seule fois, au multiple de $pasCentimes le plus
     * proche (au centime, ou à la dizaine d'euros pour un prix en X9).
     * C'est le seul prix arrondi, lors de l'enregistrement du produit.
     *
     * @throws InvalidArgumentException
     */
    public function prixTtcArrondi(float $prixHt, float $tauxTva, int $pasCentimes = 1): float
    {
        $this->verifierTauxTva($tauxTva);

        // Dix-millièmes d'euro x (100 % + taux) en centièmes de point :
        // 10^-8 €, soit 10^6 par centime.
        $prixExact = $this->multiplier($this->versUnites($prixHt, 10000), 10000 + $this->versUnites($tauxTva, 100));

        return $this->multiplier($this->diviserArrondi($prixExact, $this->multiplier(1_000_000, $pasCentimes)), $pasCentimes) / 100;
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
     * Le coût réel d'un article acheté : son PU HT moins la remise de la
     * ligne, puis l'escompte de la facture, au dix-millième d'euro comme le
     * CMUP qu'il alimente (voir coutMoyenPondere()) -- jamais arrondi au
     * centime : 1,18 € remisé de 15 % coûte 1,003 €, et 3 articles valent
     * bien les 3,01 € HT de la ligne, pas 3 x 1,00 €.
     *
     * @throws InvalidArgumentException
     */
    public function coutUnitaireNet(float $prixUnitaireHt, float $remisePourcentage, float $escomptePourcentage = 0.0): float
    {
        // (100 % - remise) x (100 % - escompte), en (centièmes de point)²,
        // soit au plus 10^8. Le coût vaut centimes x facteur / 10^6, au
        // dix-millième ; les centimes sont découpés autour de 10^6 pour
        // que le produit ne déborde jamais, avec un seul arrondi.
        $facteur = (10000 - $this->remiseEnCentiemes($remisePourcentage)) * (10000 - $this->remiseEnCentiemes($escomptePourcentage));
        $centimes = $this->versUnites($prixUnitaireHt, 100);

        $coutDixMilliemes = $this->multiplier(intdiv($centimes, 1_000_000), $facteur)
            + $this->diviserArrondi($this->multiplier($centimes % 1_000_000, $facteur), 1_000_000);

        return $coutDixMilliemes / 10000;
    }

    /**
     * Ce que rapporte un article : son prix de vente HT moins son coût
     * d'achat exact (voir coutUnitaireNet()), arrondi une seule fois au
     * centime -- 11,00 € - 1,005 € = 9,995 € -> 10,00 €.
     */
    public function beneficeUnitaire(float $prixVenteHt, float $coutUnitaireHt): float
    {
        return $this->centimesVersMontant($this->diviserArrondi(
            $this->versUnites($prixVenteHt, 10000) - $this->versUnites($coutUnitaireHt, 10000),
            100,
        ));
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
     * Montants d'un retour : ceux de toute la quantité rendue jusqu'ici
     * (retours déjà émis compris) moins ce que les retours déjà émis ont
     * crédité -- jamais un recalcul du seul retour. Recalculer chaque
     * retour à part arrondirait sa remise ou sa TVA une fois de plus : 10 x
     * 0,13 € HT (1,30 € + 0,26 € de TVA) rendus un par un créditeraient
     * 0,03 € de TVA neuf fois, et le dernier retour -0,01 €. En cumulé, la
     * TVA créditée suit 0,03, 0,05, 0,08... jusqu'aux 0,26 € de la ligne,
     * jamais négative, et le dernier retour retombe exactement sur la
     * ligne.
     *
     * @param  array{montant_ht: float|string, montant_tva: float|string, montant_ttc: float|string}  $montantsDuCumul  la ligne calculée pour toute la quantité rendue jusqu'ici
     * @param  iterable<array{montant_ht: float|string, montant_tva: float|string, montant_ttc: float|string}>  $montantsDejaCredites
     * @return array{montant_ht: float, montant_tva: float, montant_ttc: float}
     */
    public function retourCumule(array $montantsDuCumul, iterable $montantsDejaCredites): array
    {
        $dejaCredite = $this->totaliser($montantsDejaCredites);

        return [
            'montant_ht' => $this->soustraire($montantsDuCumul['montant_ht'], $dejaCredite['total_ht']),
            'montant_tva' => $this->soustraire($montantsDuCumul['montant_tva'], $dejaCredite['total_tva']),
            'montant_ttc' => $this->soustraire($montantsDuCumul['montant_ttc'], $dejaCredite['total_ttc']),
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
     * @return array{montant_ht: float, montant_tva: float, montant_ttc: float}
     */
    private function montants(int $montantHtCentimes, int $montantTtcCentimes): array
    {
        return [
            'montant_ht' => $this->centimesVersMontant($montantHtCentimes),
            'montant_tva' => $this->centimesVersMontant($montantTtcCentimes - $montantHtCentimes),
            'montant_ttc' => $this->centimesVersMontant($montantTtcCentimes),
        ];
    }

    /**
     * Une remise (ou un escompte) en centièmes de point : hors de 0-100 %,
     * elle n'existe pas.
     *
     * @throws InvalidArgumentException
     */
    private function remiseEnCentiemes(float $remisePourcentage): int
    {
        if ($remisePourcentage < 0 || $remisePourcentage > 100) {
            throw new InvalidArgumentException('La remise doit être comprise entre 0 et 100 %.');
        }

        return $this->versUnites($remisePourcentage, 100);
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
