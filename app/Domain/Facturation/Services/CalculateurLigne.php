<?php

namespace App\Domain\Facturation\Services;

use InvalidArgumentException;

/**
 * Les montants d'une vente, d'un achat et de leurs retours, tous en
 * centimes entiers. Le seul prix arrondi est le prix de vente TTC, une
 * fois, quand il est enregistré ; ensuite chaque montant s'arrondit une
 * seule fois, quand il se calcule.
 *
 * 1. Toute vente, à un particulier comme à un professionnel, ticket,
 *    facture ou devis, part de ses prix TTC (chiffrerUneVente()) : le TTC
 *    de chaque ligne est quantité x PU TTC, remise déduite, la remise
 *    arrondie une fois au centime ; pour chaque taux, le HT est le TTC de
 *    ses lignes ÷ (1 + taux), arrondi une fois au centime le plus proche
 *    (un demi-centime exact au centime supérieur), et se répartit sur ses
 *    lignes par cumul, dans leur ordre ; la TVA est la différence. Un même
 *    document de 50,00 € TTC à 20 % fait 41,67 € HT et 8,33 € de TVA, en
 *    une ligne, en 2 x 25,00 € ou en deux lignes de 25,00 € (20,83 € puis
 *    20,84 € HT) ; 2,97 € TTC à 20 % font 2,48 € HT (2,475 arrondi au
 *    centime supérieur) et 0,49 € de TVA.
 * 2. Une vente hors de la TVA française (export, livraison vers un DOM,
 *    livraison intracommunautaire...) se calcule de même, au taux compris
 *    dans le prix de son catalogue, puis sans TVA : le client paie le HT,
 *    2 x 40,00 € TTC à 20 % font 66,67 € (voir
 *    ContexteDeVente::retireLaTvaDuPrix()). Un magasin en franchise en
 *    base vend à 0 % : le HT est le TTC, sans TVA.
 * 3. Un retour (retourDeLigne()) rend, pour tous les articles d'une ligne
 *    rendus jusqu'ici, le calcul de la vente sur cette quantité, sans
 *    jamais dépasser le HT ni la TVA de la ligne, moins ce que les retours
 *    précédents ont rendu : 2 x 40,00 € (66,67 € HT + 13,33 €) se rendent
 *    en 33,33 € + 6,67 €, puis 33,34 € + 6,66 € ; le retour de toute la
 *    ligne rend exactement ses montants.
 * 4. Un achat part du PU HT du fournisseur (chiffrerUnAchat()) : le HT de
 *    chaque ligne est quantité x PU HT, remise déduite, arrondi une fois ;
 *    la TVA de chaque taux est le total HT du taux x taux, arrondie une
 *    fois et répartie sur ses lignes par cumul, dans leur ordre : 3 x
 *    10,01 € HT à 20 % font 30,03 € HT + 6,01 € de TVA (6,006 arrondi une
 *    fois), répartis 2,00 + 2,00 + 2,01. Un retour au fournisseur
 *    (retourDAchat()) rend la TVA du HT des articles retournés jusqu'ici,
 *    sans dépasser celle de la ligne, moins les retours précédents.
 * 5. Le PU HT qu'une ligne de vente enregistre et que son document
 *    imprime est son PU TTC ÷ (1 + taux), au millionième d'euro
 *    (prixUnitaireHtExact()) : 1 000 articles à 1,00 € TTC à 20 % ont un
 *    PU HT de 0,833333 €, et 1 000 x 0,833333 retombe à moins de deux
 *    centimes sur les 833,33 € HT de la ligne.
 *
 * Ces calculs n'acceptent que des entiers (centimes, nombre d'articles,
 * centièmes de point de taux et de remise) et refusent un nombre à
 * virgule : un flottant comme 8.325 vaut réellement 8,32499999..., et un
 * seul centime d'écart avec le montant transmis à l'administration suffit
 * à faire rejeter une facture. Une saisie se lit en centièmes par
 * SaisieDecimale, une colonne décimale par enCentiemes(), le PU HT d'une
 * ligne de vente par enMillioniemes().
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
     * La même borne pour un prix unitaire en millionièmes d'euro (voir
     * prixUnitaireHtExact()).
     */
    private const MILLIONIEMES_MAXIMUM = 1_000_000_000_000_000_000;

    private const NOMBRE_A_VIRGULE = 'Le calcul attend des montants en centimes et des taux et remises en centièmes de point, en nombres entiers, jamais un nombre à virgule.';

    /**
     * Les lignes d'une vente, dans leur ordre (voir le point 1 du
     * commentaire de classe) : chacune avec son PU TTC, celui de son
     * catalogue ou celui saisi, et le taux que comprend ce prix, et le
     * taux qu'elle facture -- le même, ou 0 quand la vente retire la TVA
     * du prix (point 2). Rend les montants de chaque ligne, dans le même
     * ordre, ceux de chaque taux facturé, dans l'ordre où il apparaît, et
     * ceux du document : chaque total est la somme exacte de ses lignes.
     *
     * @param  list<array{prix_unitaire_ttc: int, quantite: int, remise_pourcentage: int, taux_tva_du_prix: int, taux_tva: int}>  $lignes  PU TTC en centimes, taux et remise en centièmes de point
     * @return array{lignes: list<array{montant_ht: int, montant_tva: int, montant_ttc: int}>, taux: array<int, array{montant_ht: int, montant_tva: int, montant_ttc: int}>, total: array{montant_ht: int, montant_tva: int, montant_ttc: int}}
     *
     * @throws InvalidArgumentException
     */
    public function chiffrerUneVente(array $lignes): array
    {
        $cumuls = [];
        $montants = [];

        foreach (array_values($lignes) as $ligne) {
            $tauxDuPrix = $this->taux($ligne, 'taux_tva_du_prix');
            $taux = $this->tauxFacture($ligne, $tauxDuPrix);
            $ttcAuPrix = $this->montantNetCentimes($this->prix($ligne, 'prix_unitaire_ttc'), $this->quantite($ligne), $this->remise($ligne));

            // Les lignes d'un même taux, dans leur ordre : chacune reçoit le
            // HT arrondi du cumul des TTC jusqu'à elle, moins celui des
            // lignes précédentes.
            $cle = "{$tauxDuPrix}|{$taux}";
            $precedent = $cumuls[$cle] ?? ['ttc' => 0, 'ht' => 0];
            $cumulTtc = $this->borne($precedent['ttc'] + $ttcAuPrix);
            $cumulHt = $this->htDepuisTtc($cumulTtc, $tauxDuPrix);
            $cumuls[$cle] = ['ttc' => $cumulTtc, 'ht' => $cumulHt];

            $ht = $cumulHt - $precedent['ht'];
            // Sans la TVA française, le client paie le HT.
            $ttc = $taux === $tauxDuPrix ? $ttcAuPrix : $ht;
            $montants[] = ['taux' => $taux, 'montant_ht' => $ht, 'montant_tva' => $ttc - $ht, 'montant_ttc' => $ttc];
        }

        return $this->ventiler($montants);
    }

    /**
     * Ce que rend un retour d'une ligne vendue (voir le point 3 du
     * commentaire de classe) : pour tous les articles rendus jusqu'ici, le
     * TTC payé pour eux, et un HT au plus proche de ce TTC ÷ (1 + taux),
     * gardé entre ce que la ligne permet -- jamais plus que son HT, ni une
     * TVA plus grande que la sienne --, moins ce que les retours précédents
     * ont rendu. Le HT et la TVA rendus ne font que croître avec la
     * quantité : aucun retour n'est jamais négatif, et le retour de toute
     * la ligne rend exactement ses montants. Vendue sans la TVA française,
     * la ligne rend le HT de ces articles, au taux que comprend son prix,
     * sans TVA.
     *
     * @param  array{prix_unitaire_ttc: int, quantite: int, remise_pourcentage: int, taux_tva_du_prix: int, taux_tva: int, montant_ht: int, montant_tva: int}  $ligne  la ligne vendue, telle qu'enregistrée, en centimes et centièmes de point
     * @param  int  $quantiteRendue  tous les articles rendus jusqu'ici, ce retour compris
     * @param  array{montant_ht: int, montant_tva: int}  $dejaRendu  ce que les retours précédents de la ligne ont rendu
     * @return array{montant_ht: int, montant_tva: int, montant_ttc: int}
     *
     * @throws InvalidArgumentException
     */
    public function retourDeLigne(array $ligne, int $quantiteRendue, array $dejaRendu): array
    {
        $tauxDuPrix = $this->taux($ligne, 'taux_tva_du_prix');
        $taux = $this->tauxFacture($ligne, $tauxDuPrix);
        $quantite = $this->quantiteRendue($ligne, $quantiteRendue);
        $htDeLaLigne = $this->entier($ligne, 'montant_ht');
        $tvaDeLaLigne = $this->entier($ligne, 'montant_tva');

        if ($quantite === $this->quantite($ligne)) {
            return $this->moins($htDeLaLigne, $tvaDeLaLigne, $dejaRendu);
        }

        $ttcAuPrix = $this->montantNetCentimes($this->prix($ligne, 'prix_unitaire_ttc'), $quantite, $this->remise($ligne));
        $htAuPrix = $this->htDepuisTtc($ttcAuPrix, $tauxDuPrix);

        if ($taux !== $tauxDuPrix) {
            return $this->moins(min($htAuPrix, $htDeLaLigne), 0, $dejaRendu);
        }

        $ht = min($htDeLaLigne, max($htAuPrix, $ttcAuPrix - $tvaDeLaLigne));

        return $this->moins($ht, $ttcAuPrix - $ht, $dejaRendu);
    }

    /**
     * Les lignes d'un achat, dans leur ordre (voir le point 4 du
     * commentaire de classe). Rend les montants de chaque ligne, dans le
     * même ordre, ceux de chaque taux, dans l'ordre où il apparaît, et
     * ceux de la facture : chaque total est la somme exacte de ses lignes,
     * et la TVA de chaque taux celle de son total HT, arrondie une fois.
     *
     * @param  list<array{prix_unitaire_ht: int, quantite: int, remise_pourcentage: int, taux_tva: int}>  $lignes  PU HT en centimes, taux et remise en centièmes de point
     * @return array{lignes: list<array{montant_ht: int, montant_tva: int, montant_ttc: int}>, taux: array<int, array{montant_ht: int, montant_tva: int, montant_ttc: int}>, total: array{montant_ht: int, montant_tva: int, montant_ttc: int}}
     *
     * @throws InvalidArgumentException
     */
    public function chiffrerUnAchat(array $lignes): array
    {
        $cumuls = [];
        $montants = [];

        foreach (array_values($lignes) as $ligne) {
            $taux = $this->taux($ligne, 'taux_tva');
            $ht = $this->montantNetCentimes($this->prix($ligne, 'prix_unitaire_ht'), $this->quantite($ligne), $this->remise($ligne));

            // Chaque ligne reçoit la TVA arrondie du cumul des HT de son taux
            // jusqu'à elle, moins celle des lignes précédentes.
            $precedent = $cumuls[$taux] ?? ['ht' => 0, 'tva' => 0];
            $cumulHt = $this->borne($precedent['ht'] + $ht);
            $cumulTva = $this->tvaDuHt($cumulHt, $taux);
            $cumuls[$taux] = ['ht' => $cumulHt, 'tva' => $cumulTva];

            $tva = $cumulTva - $precedent['tva'];
            $montants[] = ['taux' => $taux, 'montant_ht' => $ht, 'montant_tva' => $tva, 'montant_ttc' => $ht + $tva];
        }

        return $this->ventiler($montants);
    }

    /**
     * Ce que rend un retour au fournisseur d'une ligne d'achat (voir le
     * point 4 du commentaire de classe) : pour tous les articles retournés
     * jusqu'ici, leur HT, quantité x PU HT remise déduite, et la TVA de ce
     * HT, jamais plus grande que celle de la ligne -- sa part de la TVA de
     * son taux peut être inférieure d'un centime à la TVA de son propre
     * HT --, moins ce que les retours précédents ont rendu. Aucun retour
     * n'est jamais négatif, et le retour de toute la ligne rend exactement
     * ses montants.
     *
     * @param  array{prix_unitaire_ht: int, quantite: int, remise_pourcentage: int, taux_tva: int, montant_ht: int, montant_tva: int}  $ligne  la ligne achetée, telle qu'enregistrée, en centimes et centièmes de point
     * @param  int  $quantiteRetournee  tous les articles retournés jusqu'ici, ce retour compris
     * @param  array{montant_ht: int, montant_tva: int}  $dejaRendu  ce que les retours précédents de la ligne ont rendu
     * @return array{montant_ht: int, montant_tva: int, montant_ttc: int}
     *
     * @throws InvalidArgumentException
     */
    public function retourDAchat(array $ligne, int $quantiteRetournee, array $dejaRendu): array
    {
        $taux = $this->taux($ligne, 'taux_tva');
        $quantite = $this->quantiteRendue($ligne, $quantiteRetournee);
        $htDeLaLigne = $this->entier($ligne, 'montant_ht');
        $tvaDeLaLigne = $this->entier($ligne, 'montant_tva');

        if ($quantite === $this->quantite($ligne)) {
            return $this->moins($htDeLaLigne, $tvaDeLaLigne, $dejaRendu);
        }

        $ht = $this->montantNetCentimes($this->prix($ligne, 'prix_unitaire_ht'), $quantite, $this->remise($ligne));

        return $this->moins($ht, min($tvaDeLaLigne, $this->tvaDuHt($ht, $taux)), $dejaRendu);
    }

    /**
     * Un prix TTC sans sa TVA, au centime le plus proche, un demi-centime
     * au centime supérieur -- 40,00 € TTC à 20 % font 33,33 € ; 10,00 € à
     * 5,5 %, 9,48 € : le prix HT d'un produit que montrent sa fiche et le
     * barème, et le prix sans TVA d'un service, saisi au centime, hors de
     * la TVA française. Le PU HT qu'enregistre une ligne de vente est plus
     * précis (voir prixUnitaireHtExact()).
     *
     * @param  int  $prixUnitaireTtc  en centimes
     * @param  int  $tauxTva  en centièmes de point
     * @return int en centimes
     *
     * @throws InvalidArgumentException
     */
    public function prixUnitaireHtDepuisTtc(int $prixUnitaireTtc, int $tauxTva): int
    {
        return $this->htDepuisTtc(
            $this->prix(['prix_unitaire_ttc' => $prixUnitaireTtc], 'prix_unitaire_ttc'),
            $this->taux(['taux_tva' => $tauxTva], 'taux_tva'),
        );
    }

    /**
     * Le PU HT qu'enregistre une ligne de vente (Q1-b) : son PU TTC ÷ (1 +
     * le taux compris dans ce prix), au millionième d'euro le plus proche,
     * un demi-millionième au millionième supérieur -- 1,00 € TTC à 20 %
     * fait 0,833333 € ; 12,00 €, 10,00 € ; 9,99 €, 8,325 €. Les montants de
     * la ligne se calculent sur son TTC (voir chiffrerUneVente()), et la
     * quantité x ce PU HT, remise déduite, s'écarte du HT de la ligne de
     * moins de deux centimes jusqu'à 10 000 articles : moins d'un centime
     * pour la part du HT de son taux que la ligne reçoit (la différence de
     * deux arrondis au centime), un demi-centime au plus pour la remise,
     * arrondie une fois au centime sur son TTC, et un demi-centime au plus
     * pour le millionième (0,0000005 € x 10 000). C'est la tolérance de la
     * facture électronique (règle Peppol R120 : 0,02 €).
     *
     * @param  int  $prixUnitaireTtc  en centimes
     * @param  int  $tauxTva  en centièmes de point
     * @return int en millionièmes d'euro
     *
     * @throws InvalidArgumentException
     */
    public function prixUnitaireHtExact(int $prixUnitaireTtc, int $tauxTva): int
    {
        $diviseur = 10000 + $this->taux(['taux_tva' => $tauxTva], 'taux_tva');
        $ttcEnMillioniemes = $this->multiplier($this->prix(['prix_unitaire_ttc' => $prixUnitaireTtc], 'prix_unitaire_ttc'), 10000);

        // TTC x 10 000 ÷ (10 000 + taux), en deux temps : le produit
        // dépasserait les entiers pour les plus grands prix des colonnes.
        return $this->multiplier(intdiv($ttcEnMillioniemes, $diviseur), 10000)
            + $this->diviserArrondi(($ttcEnMillioniemes % $diviseur) * 10000, $diviseur);
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
     * Σ quantité. Des lots de coût nul (marchandise offerte) coûtent 0 ;
     * des lots qui ne pèsent rien (aucun article) n'ont pas de coût moyen :
     * null. Une quantité ou un coût négatif n'a pas de sens.
     *
     * @param  iterable<array{quantite: int, cout_unitaire: float|string}>  $lots
     *
     * @throws InvalidArgumentException
     */
    public function coutMoyenPondere(iterable $lots): ?float
    {
        $quantiteTotale = 0;
        $valeurTotaleDixMilliemes = 0;

        foreach ($lots as $lot) {
            $coutDixMilliemes = $this->versUnites((float) $lot['cout_unitaire'], 10000);

            if ($lot['quantite'] < 0 || $coutDixMilliemes < 0) {
                throw new InvalidArgumentException('Un lot de stock ne peut avoir ni quantité ni coût négatif.');
            }

            $quantiteTotale += $lot['quantite'];
            $valeurTotaleDixMilliemes += $this->multiplier($coutDixMilliemes, $lot['quantite']);
        }

        if ($quantiteTotale === 0) {
            return null;
        }

        return $this->diviserArrondi($valeurTotaleDixMilliemes, $quantiteTotale) / 10000;
    }

    /**
     * Le coût moyen du stock restant quand $quantite articles en sortent à
     * un autre coût que le sien -- un retour au fournisseur, au coût de la
     * ligne d'achat retournée (voir StockLedgerService::retournerAuFournisseur()) :
     * (stock x coût moyen - quantité x coût) / (stock - quantité), au
     * dix-millième d'euro. 10 articles achetés à 2 €, puis 10 à 10 € (20 à
     * 6 €) : retourner les 10 à 10 € laisse 10 articles à 2 €. Sans stock
     * restant, il n'y a plus de coût moyen à recalculer : null, le coût
     * moyen ne change pas. Un stock ne vaut jamais moins que rien : un
     * retour qui rendrait négative la valeur du stock restant la ramène à
     * 0 € -- 5 articles à 6 € dont 4 retournés à 10 € : le dernier vaut 0 €,
     * pas -10 €. Une quantité ou un coût négatif n'a pas de sens.
     *
     * @throws InvalidArgumentException
     */
    public function coutMoyenApresUneSortie(int $stock, float $coutMoyen, int $quantite, float $coutUnitaire): ?float
    {
        $coutMoyenDixMilliemes = $this->versUnites($coutMoyen, 10000);
        $coutUnitaireDixMilliemes = $this->versUnites($coutUnitaire, 10000);

        if ($stock < 0 || $quantite < 0 || $coutMoyenDixMilliemes < 0 || $coutUnitaireDixMilliemes < 0) {
            throw new InvalidArgumentException('Un lot de stock ne peut avoir ni quantité ni coût négatif.');
        }

        $reste = $stock - $quantite;

        if ($reste <= 0) {
            return null;
        }

        $valeurRestanteDixMilliemes = $this->multiplier($coutMoyenDixMilliemes, $stock) - $this->multiplier($coutUnitaireDixMilliemes, $quantite);

        if ($valeurRestanteDixMilliemes < 0) {
            return 0.0;
        }

        return $this->diviserArrondi($valeurRestanteDixMilliemes, $reste) / 10000;
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
     * Cumul de documents déjà totalisés (tickets en attente de clôture,
     * chiffre d'affaires, relevé « Fermer ma caisse ») : les mêmes sommes
     * séparées des HT, des TVA et des TTC qu'entre les lignes d'un
     * document.
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
     * Un montant, un taux ou une remise enregistré au centième
     * (decimal(12,2) ou decimal(5,2), lu en chaîne par le cast decimal:2),
     * en centièmes entiers : « 120.00 » vaut 12000, « -0.05 » vaut -5, un
     * entier compte des unités entières (20 vaut 2000). Seule une écriture
     * décimale exacte est acceptée : des chiffres, au plus deux décimales
     * après un point, un « - » facultatif, aucun espace ni signe « + » ;
     * des zéros de tête ne changent rien (« 0007.5 » vaut 750). Comme
     * partout dans la classe, la borne est UNITES_MAXIMUM, au-delà des
     * colonnes : un montant qu'aucune colonne ne contiendrait se lit
     * encore sous cette borne. Un flottant est refusé, même rond : rien ne
     * dit qu'il soit la valeur exacte d'une colonne (0.1 + 0.2 vaut
     * 0.30000000000000004), et PHP le convertirait en silence avant
     * l'appel (1.5 deviendrait l'entier 1) si le paramètre n'acceptait que
     * des chaînes et des entiers.
     *
     * @param  string|int  $valeur
     *
     * @throws InvalidArgumentException
     */
    public function enCentiemes(mixed $valeur): int
    {
        if (is_int($valeur)) {
            $centiemes = $this->multiplier($valeur, 100);
        } elseif (is_string($valeur) && preg_match('/^(-?)(\d+)(?:\.(\d{1,2}))?$/D', $valeur, $morceaux) === 1) {
            $unites = ltrim($morceaux[2], '0');

            // Plus de 12 chiffres dépassent la borne de toute façon, et
            // leur conversion en entier saturerait en silence.
            if (strlen($unites) > 12) {
                throw new InvalidArgumentException('Montant hors limites.');
            }

            $centiemes = ($morceaux[1] === '-' ? -1 : 1) * ((int) $unites * 100 + (int) str_pad($morceaux[3] ?? '', 2, '0'));
        } else {
            throw new InvalidArgumentException('Montant illisible : une chaîne décimale exacte (deux décimales au plus) ou un entier est attendu.');
        }

        if (abs($centiemes) >= self::UNITES_MAXIMUM) {
            throw new InvalidArgumentException('Montant hors limites.');
        }

        return $centiemes;
    }

    /**
     * L'inverse d'enCentiemes() : 12000 s'écrit « 120.00 », -5 s'écrit
     * « -0.05 », comme le cast decimal:2.
     *
     * @throws InvalidArgumentException
     */
    public function enDecimal(int $centiemes): string
    {
        if (abs($centiemes) >= self::UNITES_MAXIMUM) {
            throw new InvalidArgumentException('Montant hors limites.');
        }

        return sprintf('%s%d.%02d', $centiemes < 0 ? '-' : '', intdiv(abs($centiemes), 100), abs($centiemes) % 100);
    }

    /**
     * Un montant en centimes tel qu'un écran l'écrit : 123450 s'écrit
     * « 1 234,50 € », -5 s'écrit « -0,05 € » ; même borne qu'enDecimal().
     *
     * @throws InvalidArgumentException
     */
    public function enEuros(int $centiemes): string
    {
        [$unites, $decimales] = explode('.', ltrim($this->enDecimal($centiemes), '-'));

        return ($centiemes < 0 ? '-' : '').number_format((int) $unites, 0, ',', ' ').','.$decimales.' €';
    }

    /**
     * Un PU HT de ligne de vente enregistré au millionième d'euro
     * (decimal(18,6), lu en chaîne par le cast decimal:6, voir
     * prixUnitaireHtExact()), ou tout prix enregistré au centime, en
     * millionièmes entiers : « 0.833333 » vaut 833333, « 10.01 » vaut
     * 10010000, un entier compte des euros (12 vaut 12000000). Mêmes règles
     * qu'enCentiemes() : une écriture décimale exacte, six décimales au
     * plus, jamais un flottant, et la même borne, en millionièmes.
     *
     * @param  string|int  $valeur
     *
     * @throws InvalidArgumentException
     */
    public function enMillioniemes(mixed $valeur): int
    {
        if (is_int($valeur)) {
            $millioniemes = $this->multiplier($valeur, 1_000_000);
        } elseif (is_string($valeur) && preg_match('/^(-?)(\d+)(?:\.(\d{1,6}))?$/D', $valeur, $morceaux) === 1) {
            $unites = ltrim($morceaux[2], '0');

            if (strlen($unites) > 12) {
                throw new InvalidArgumentException('Montant hors limites.');
            }

            $millioniemes = ($morceaux[1] === '-' ? -1 : 1) * ((int) $unites * 1_000_000 + (int) str_pad($morceaux[3] ?? '', 6, '0'));
        } else {
            throw new InvalidArgumentException('Prix illisible : une chaîne décimale exacte (six décimales au plus) ou un entier est attendu.');
        }

        if (abs($millioniemes) >= self::MILLIONIEMES_MAXIMUM) {
            throw new InvalidArgumentException('Montant hors limites.');
        }

        return $millioniemes;
    }

    /**
     * L'inverse d'enMillioniemes(), comme le cast decimal:6 : 833333
     * s'écrit « 0.833333 », 10000000 « 10.000000 ».
     *
     * @throws InvalidArgumentException
     */
    public function enDecimalAuMillionieme(int $millioniemes): string
    {
        if (abs($millioniemes) >= self::MILLIONIEMES_MAXIMUM) {
            throw new InvalidArgumentException('Montant hors limites.');
        }

        return sprintf('%s%d.%06d', $millioniemes < 0 ? '-' : '', intdiv(abs($millioniemes), 1_000_000), abs($millioniemes) % 1_000_000);
    }

    /**
     * Un prix en millionièmes avec ses décimales utiles, deux au moins,
     * tel qu'un champ le montre : « 0.833333 », « 8.325 », « 12.00 ».
     *
     * @throws InvalidArgumentException
     */
    public function enDecimalUtile(int $millioniemes): string
    {
        [$unites, $decimales] = explode('.', $this->enDecimalAuMillionieme($millioniemes));

        return $unites.'.'.str_pad(rtrim($decimales, '0'), 2, '0');
    }

    /**
     * Un prix en millionièmes tel qu'un document l'imprime, avec ses
     * décimales utiles, deux au moins : « 0,833333 € », « 8,325 € »,
     * « 1 000,00 € ».
     *
     * @throws InvalidArgumentException
     */
    public function prixUnitaireEnEuros(int $millioniemes): string
    {
        [$unites, $decimales] = explode('.', ltrim($this->enDecimalUtile($millioniemes), '-'));

        return ($millioniemes < 0 ? '-' : '').number_format((int) $unites, 0, ',', ' ').','.$decimales.' €';
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
     * Le HT d'un TTC : TTC ÷ (1 + taux), arrondi une fois au centime le
     * plus proche, un demi-centime au centime supérieur.
     */
    private function htDepuisTtc(int $ttcCentimes, int $tauxCentiemes): int
    {
        return $this->diviserArrondi($this->multiplier($ttcCentimes, 10000), 10000 + $tauxCentiemes);
    }

    /**
     * La TVA d'un HT : HT x taux, arrondie une fois au centime le plus
     * proche, un demi-centime au centime supérieur.
     */
    private function tvaDuHt(int $htCentimes, int $tauxCentiemes): int
    {
        return $this->diviserArrondi($this->multiplier($htCentimes, $tauxCentiemes), 10000);
    }

    /**
     * Ce que rend un retour : le cumul de la quantité rendue jusqu'ici,
     * moins ce que les retours précédents ont rendu ; son TTC est la somme
     * de son HT et de sa TVA.
     *
     * @param  array{montant_ht: int, montant_tva: int}  $dejaRendu
     * @return array{montant_ht: int, montant_tva: int, montant_ttc: int}
     *
     * @throws InvalidArgumentException
     */
    private function moins(int $htCumule, int $tvaCumulee, array $dejaRendu): array
    {
        $ht = $htCumule - $this->entier($dejaRendu, 'montant_ht');
        $tva = $tvaCumulee - $this->entier($dejaRendu, 'montant_tva');

        return ['montant_ht' => $ht, 'montant_tva' => $tva, 'montant_ttc' => $ht + $tva];
    }

    /**
     * Les montants de chaque ligne, ceux de chaque taux (somme de ses
     * lignes, dans l'ordre où il apparaît) et ceux du document (somme de
     * toutes les lignes).
     *
     * @param  list<array{taux: int, montant_ht: int, montant_tva: int, montant_ttc: int}>  $montants
     * @return array{lignes: list<array{montant_ht: int, montant_tva: int, montant_ttc: int}>, taux: array<int, array{montant_ht: int, montant_tva: int, montant_ttc: int}>, total: array{montant_ht: int, montant_tva: int, montant_ttc: int}}
     */
    private function ventiler(array $montants): array
    {
        $zero = ['montant_ht' => 0, 'montant_tva' => 0, 'montant_ttc' => 0];
        $lignes = [];
        $parTaux = [];
        $total = $zero;

        foreach ($montants as ['taux' => $taux, 'montant_ht' => $ht, 'montant_tva' => $tva, 'montant_ttc' => $ttc]) {
            $lignes[] = ['montant_ht' => $ht, 'montant_tva' => $tva, 'montant_ttc' => $ttc];
            $duTaux = $parTaux[$taux] ?? $zero;
            $parTaux[$taux] = ['montant_ht' => $duTaux['montant_ht'] + $ht, 'montant_tva' => $duTaux['montant_tva'] + $tva, 'montant_ttc' => $duTaux['montant_ttc'] + $ttc];
            $total = ['montant_ht' => $total['montant_ht'] + $ht, 'montant_tva' => $total['montant_tva'] + $tva, 'montant_ttc' => $total['montant_ttc'] + $ttc];
        }

        return ['lignes' => $lignes, 'taux' => $parTaux, 'total' => $total];
    }

    /**
     * Une valeur entière d'une ligne : jamais un nombre à virgule, que PHP
     * tronquerait en silence (1,5 deviendrait 1), ni une chaîne à
     * interpréter.
     *
     * @param  array<string, mixed>  $ligne
     *
     * @throws InvalidArgumentException
     */
    private function entier(array $ligne, string $cle): int
    {
        $valeur = $ligne[$cle] ?? null;

        if (! is_int($valeur)) {
            throw new InvalidArgumentException(self::NOMBRE_A_VIRGULE);
        }

        if (abs($valeur) >= self::UNITES_MAXIMUM) {
            throw new InvalidArgumentException('Montant hors limites.');
        }

        return $valeur;
    }

    /**
     * Un prix unitaire en centimes : jamais négatif, une vente ne
     * rembourse rien, c'est le rôle d'un avoir.
     *
     * @param  array<string, mixed>  $ligne
     *
     * @throws InvalidArgumentException
     */
    private function prix(array $ligne, string $cle): int
    {
        $prix = $this->entier($ligne, $cle);

        if ($prix < 0) {
            throw new InvalidArgumentException('Un prix unitaire ne peut pas être négatif.');
        }

        return $prix;
    }

    /**
     * Un taux de TVA en centièmes de point : hors de 0-100 %, il n'existe
     * pas -- et -100 % diviserait par zéro en passant du TTC au HT.
     *
     * @param  array<string, mixed>  $ligne
     *
     * @throws InvalidArgumentException
     */
    private function taux(array $ligne, string $cle): int
    {
        $taux = $this->entier($ligne, $cle);

        if ($taux < 0 || $taux > 10000) {
            throw new InvalidArgumentException('Le taux de TVA doit être compris entre 0 et 100 %.');
        }

        return $taux;
    }

    /**
     * Le taux qu'une ligne de vente facture : celui que comprend son prix,
     * ou 0 quand la vente retire la TVA du prix (voir le point 2 du
     * commentaire de classe) ; aucun autre.
     *
     * @param  array<string, mixed>  $ligne
     *
     * @throws InvalidArgumentException
     */
    private function tauxFacture(array $ligne, int $tauxDuPrix): int
    {
        $taux = $this->taux($ligne, 'taux_tva');

        if ($taux !== $tauxDuPrix && $taux !== 0) {
            throw new InvalidArgumentException('Une ligne facture le taux que comprend son prix, ou aucun.');
        }

        return $taux;
    }

    /**
     * La remise d'une ligne en centièmes de point, entre 0 et 100 %.
     *
     * @param  array<string, mixed>  $ligne
     *
     * @throws InvalidArgumentException
     */
    private function remise(array $ligne): int
    {
        $remise = $this->entier($ligne, 'remise_pourcentage');

        if ($remise < 0 || $remise > 10000) {
            throw new InvalidArgumentException('La remise doit être comprise entre 0 et 100 %.');
        }

        return $remise;
    }

    /**
     * Le nombre d'articles d'une ligne : un entier, au moins 1 (voir
     * QuantiteEntiere).
     *
     * @param  array<string, mixed>  $ligne
     *
     * @throws InvalidArgumentException
     */
    private function quantite(array $ligne): int
    {
        $quantite = $this->entier($ligne, 'quantite');

        if ($quantite < 1) {
            throw new InvalidArgumentException('La quantité doit être un nombre entier d\'articles, au moins 1.');
        }

        return $quantite;
    }

    /**
     * Les articles d'une ligne rendus jusqu'ici : entre 1 et sa quantité.
     *
     * @param  array<string, mixed>  $ligne
     *
     * @throws InvalidArgumentException
     */
    private function quantiteRendue(array $ligne, int $quantiteRendue): int
    {
        if ($quantiteRendue < 1 || $quantiteRendue > $this->quantite($ligne)) {
            throw new InvalidArgumentException('Un retour rend entre 1 article et la quantité de la ligne.');
        }

        return $quantiteRendue;
    }

    /**
     * Un cumul de montants refusé au-delà de la borne de la classe.
     *
     * @throws InvalidArgumentException
     */
    private function borne(int $centimes): int
    {
        if (abs($centimes) >= self::UNITES_MAXIMUM) {
            throw new InvalidArgumentException('Montant hors limites.');
        }

        return $centimes;
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
