<?php

declare(strict_types=1);

namespace App\Domain\Facturation\Services;

use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
use InvalidArgumentException;
use UnexpectedValueException;

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
 * 4. Un achat garde les montants qu'écrit la facture du fournisseur
 *    (chiffrerUnAchat(), décision de l'éditeur du 02/11/2026, T1) : son PU
 *    HT a six décimales au plus ; le HT proposé de chaque ligne est
 *    quantité x PU HT, remise déduite, arrondi une fois, et la TVA
 *    proposée de chaque taux est le total HT du taux x taux, arrondie une
 *    fois et répartie sur ses lignes par cumul, dans leur ordre : 3
 *    lignes de 10,01 € HT à 20 % font 30,03 € HT + 6,01 € de TVA (6,006
 *    arrondi une fois), répartis 2,00 + 2,00 + 2,01. Un HT de ligne ou une
 *    TVA de taux écrits par le fournisseur remplacent ces propositions ;
 *    l'écart entre la TVA retenue et la proposée se répartit sur les
 *    lignes du taux au prorata de leur HT, par cumul : avec 6,00 € de TVA
 *    du fournisseur sur les mêmes lignes, l'écart de -1 centime vaut, au
 *    cumul des HT, -0,33, -0,67 puis -1 centime, arrondis à 0, -1 puis -1 :
 *    seule la deuxième ligne perd un centime, soit 2,00 + 1,99 + 2,01. Un
 *    retour au fournisseur (retourDAchat()) propose la TVA du HT des
 *    articles retournés jusqu'ici, sans dépasser celle de la ligne, moins
 *    les retours précédents ; quand la note d'avoir du fournisseur écrit
 *    un autre HT, la TVA proposée suit ce HT.
 * 5. Le PU HT qu'une ligne de vente enregistre est son PU TTC ÷ (1 +
 *    taux), au millionième d'euro (prixUnitaireHtExact()) : 1 000
 *    articles à 1,00 € TTC à 20 % ont un PU HT de 0,833333 €, et 1 000 x
 *    0,833333 retombe à moins de deux centimes sur les 833,33 € HT de la
 *    ligne. Les documents qui montrent le PU HT (factures, avoirs sur
 *    facture et devis à un professionnel, ventes sans TVA française)
 *    l'affichent tel qu'il est enregistré, 0,833333 €
 *    (prixUnitaireExact(), décision de l'éditeur du 09/11/2026) ; tout
 *    autre prix unitaire de vente montré en lecture s'affiche arrondi au
 *    centime, 0,83 € (prixUnitaireAffiche()). Aucun montant ne se calcule
 *    sur un prix affiché : 4 x 65,00 € TTC à 20 % font 216,67 € HT, et non
 *    4 x 54,17 € = 216,68 € ; la note de calcul du document l'explique.
 *
 * Ces calculs n'acceptent et ne rendent que des entiers, chacun dans son
 * unité : centimes d'un montant, dix-millièmes d'euro d'un coût d'achat
 * ou d'un coût moyen, millionièmes d'euro d'un PU HT, nombre d'articles,
 * centièmes de point d'un taux, d'une remise ou d'un escompte. Aucun
 * nombre à virgule flottante n'y entre ni n'en sort : un flottant comme
 * 8.325 vaut réellement 8,32499999..., et un seul centime d'écart avec le
 * montant transmis à l'administration suffit à faire rejeter une
 * facture. Une saisie se lit par SaisieDecimale, une colonne décimale par
 * enCentiemes(), enDixMilliemes() ou enMillioniemes(), et un entier
 * s'écrit dans sa colonne par enDecimal(), enDecimalAuDixMillieme() ou
 * enDecimalAuMillionieme().
 */
class CalculateurLigne
{
    /**
     * Bien au-delà de tout montant réel (les colonnes s'arrêtent à
     * 9 999 999 999,99 €, soit 1e12 centimes) : un montant absurde est
     * refusé plutôt que converti.
     */
    private const UNITES_MAXIMUM = 100_000_000_000_000;

    /**
     * La même borne pour un prix unitaire en millionièmes d'euro (voir
     * prixUnitaireHtExact()).
     */
    private const MILLIONIEMES_MAXIMUM = 1_000_000_000_000_000_000;

    private const NOMBRE_A_VIRGULE = 'Le calcul attend des montants en centimes et des taux et remises en centièmes de point, en nombres entiers, jamais un nombre à virgule.';

    public const REFUS_HT_NEGATIF = 'Le HT d\'une ligne de la facture du fournisseur ne peut pas être négatif.';

    public const REFUS_TVA_SANS_LIGNE = 'Aucune ligne de la facture n\'est à %s : sa TVA ne se saisit pas.';

    public const REFUS_TVA_NEGATIVE = 'La TVA à %s ne peut pas être négative.';

    public const REFUS_TVA_A_ZERO = 'Une ligne à 0 % n\'a pas de TVA : la TVA à 0 % vaut 0,00 €.';

    public const REFUS_TVA_AU_DELA_DE_LA_BASE = 'La TVA à %s (%s) ne peut pas dépasser sa base (%s).';

    /**
     * Les lignes d'une vente, dans leur ordre (voir le point 1 du
     * commentaire de classe) : chacune avec son PU TTC, celui de son
     * catalogue ou celui saisi, et le taux que comprend ce prix, et le
     * taux qu'elle facture -- le même, ou 0 quand la vente retire la TVA
     * du prix (point 2). Rend les montants de chaque ligne, dans le même
     * ordre, ceux de chaque taux facturé, dans l'ordre où il apparaît, et
     * ceux du document : chaque total est la somme exacte de ses lignes.
     *
     * @param  array<array-key, array{prix_unitaire_ttc: int, quantite: int, remise_pourcentage: int, taux_tva_du_prix: int, taux_tva: int}>  $lignes  PU TTC en centimes, taux et remise en centièmes de point
     * @return array{lignes: list<array{montant_ht: int, montant_tva: int, montant_ttc: int}>, taux: array<int, array{montant_ht: int, montant_tva: int, montant_ttc: int}>, total: array{montant_ht: int, montant_tva: int, montant_ttc: int}}
     *
     * @throws InvalidArgumentException
     */
    public function chiffrerUneVente(array $lignes): array
    {
        $cumuls = [];
        $montants = [];

        foreach ($lignes as $ligne) {
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
     * commentaire de classe), et la TVA qu'écrit la facture du fournisseur
     * pour les taux où elle n'est pas celle que le calcul propose. Rend les
     * montants de chaque ligne, dans le même ordre, avec le HT que le calcul
     * propose (montant_ht_calcule), ceux de chaque taux, dans l'ordre où il
     * apparaît, avec la TVA que le calcul propose (montant_tva_calcule), et
     * ceux de la facture : chaque total est la somme exacte de ses lignes.
     *
     * Le HT d'une ligne est celui qu'écrit le fournisseur (montant_ht),
     * sinon celui que le calcul propose (voir htDUnAchat()) ; la base d'un
     * taux est la somme exacte des HT de ses lignes. La TVA proposée d'un
     * taux est sa base x taux, arrondie une fois, et chaque ligne en reçoit
     * la part arrondie du cumul des HT de son taux jusqu'à elle, moins
     * celle des lignes précédentes. Quand la facture écrit une autre TVA,
     * l'écart se répartit sur les lignes du taux de même, au prorata de
     * leur HT, par cumul, dans leur ordre ; aucune ligne ne reçoit une TVA
     * négative ni plus grande que son HT : ce que l'arrondi lui donnerait
     * en trop ou en moins se reporte sur la ligne suivante, puis, après la
     * dernière, sur les précédentes. La TVA du taux est toujours exactement
     * la somme de ses lignes.
     *
     * Sont refusés : un HT écrit négatif ; une TVA écrite pour un taux sans
     * ligne, négative, non nulle à 0 % ou plus grande que sa base -- des
     * montants qu'aucune facture valable ne porte.
     *
     * @param  array<array-key, array{prix_unitaire_ht: int, quantite: int, remise_pourcentage: int, taux_tva: int, montant_ht?: int|null}>  $lignes  PU HT en millionièmes d'euro, taux et remise en centièmes de point, HT écrit par le fournisseur en centimes
     * @param  array<int, int>  $tvaEcriteParTaux  la TVA qu'écrit la facture, en centimes, par taux en centièmes de point
     * @return array{lignes: list<array{montant_ht: int, montant_tva: int, montant_ttc: int, montant_ht_calcule: int}>, taux: array<int, array{montant_ht: int, montant_tva: int, montant_ttc: int, montant_tva_calcule: int}>, total: array{montant_ht: int, montant_tva: int, montant_ttc: int}}
     *
     * @throws InvalidArgumentException
     */
    public function chiffrerUnAchat(array $lignes, array $tvaEcriteParTaux = []): array
    {
        $cumuls = [];
        $montants = [];

        foreach ($lignes as $ligne) {
            $taux = $this->taux($ligne, 'taux_tva');
            $htCalcule = $this->htDUnAchat($this->prixAuMillionieme($ligne), $this->quantite($ligne), $this->remise($ligne));
            $ht = ($ligne['montant_ht'] ?? null) === null ? $htCalcule : $this->htEcrit($ligne);

            // Chaque ligne reçoit la TVA arrondie du cumul des HT de son taux
            // jusqu'à elle, moins celle des lignes précédentes.
            $precedent = $cumuls[$taux] ?? ['ht' => 0, 'tva' => 0];
            $cumulHt = $this->borne($precedent['ht'] + $ht);
            $cumulTva = $this->tvaDuHt($cumulHt, $taux);
            $cumuls[$taux] = ['ht' => $cumulHt, 'tva' => $cumulTva];

            $montants[] = ['taux' => $taux, 'montant_ht' => $ht, 'montant_tva' => $cumulTva - $precedent['tva'], 'montant_ht_calcule' => $htCalcule];
        }

        $tvaParTaux = [];

        foreach ($tvaEcriteParTaux as $taux => $tva) {
            if (! isset($cumuls[$taux])) {
                throw new InvalidArgumentException(sprintf(self::REFUS_TVA_SANS_LIGNE, self::tauxEnTexte($taux)));
            }

            $tvaParTaux[$taux] = $this->entier(['tva' => $tva], 'tva');
        }

        foreach ($cumuls as $taux => ['ht' => $base, 'tva' => $tvaCalculee]) {
            $tva = $tvaParTaux[$taux] ?? $tvaCalculee;
            $this->refuserUneTvaImpossible($taux, $base, $tva);

            if ($tva !== $tvaCalculee) {
                $montants = $this->repartirLEcart($montants, $taux, $base, $tva - $tvaCalculee);
            }
        }

        // Les montants de chaque ligne, relus comme des entiers (la
        // répartition d'un écart les rend sans forme connue), et son TTC.
        $lignesAvecTtc = [];

        foreach ($montants as $ligne) {
            $ht = ValeursLues::entier($ligne['montant_ht']);
            $tva = ValeursLues::entier($ligne['montant_tva']);
            $lignesAvecTtc[] = ['taux' => ValeursLues::entier($ligne['taux']), 'montant_ht' => $ht, 'montant_tva' => $tva, 'montant_ht_calcule' => ValeursLues::entier($ligne['montant_ht_calcule']), 'montant_ttc' => $ht + $tva];
        }

        $ventilation = $this->ventiler($lignesAvecTtc);
        $parTaux = [];

        foreach ($ventilation['taux'] as $taux => $montantsDuTaux) {
            $parTaux[$taux] = [...$montantsDuTaux, 'montant_tva_calcule' => $cumuls[$taux]['tva']];
        }

        return [
            'lignes' => array_map(
                fn (array $montantsDeLigne, array $ligne): array => [...$montantsDeLigne, 'montant_ht_calcule' => $ligne['montant_ht_calcule']],
                $ventilation['lignes'],
                $lignesAvecTtc,
            ),
            'taux' => $parTaux,
            'total' => $ventilation['total'],
        ];
    }

    /**
     * Ce que propose un retour au fournisseur d'une ligne d'achat (voir le
     * point 4 du commentaire de classe) : pour tous les articles retournés
     * jusqu'ici, leur HT, quantité x PU HT remise déduite, jamais plus que
     * le HT de la ligne, et la TVA de ce HT, jamais plus grande que celle
     * de la ligne -- sa part de la TVA de son taux peut être inférieure
     * d'un centime à la TVA de son propre HT --, moins ce que les retours
     * précédents ont rendu ; le retour de toute la ligne, ce qui en reste.
     * Rien n'est jamais négatif : la note d'avoir du fournisseur, qui
     * prime, a pu rendre plus que la proposition d'un retour précédent.
     *
     * Quand la note d'avoir écrit pour ce retour un autre HT que celui
     * proposé (montant_ht_calcule, gardé pour l'y comparer), il le remplace,
     * et la TVA proposée suit ce HT, comme celle d'une facture d'achat suit
     * sa base (voir chiffrerUnAchat()) : la TVA de tout le HT retenu des
     * retours de la ligne, ce HT compris, jamais plus grande que celle de
     * la ligne, moins ce que les retours précédents ont rendu, et jamais
     * plus grande que le HT de ce retour, qu'aucune note d'avoir valable ne
     * dépasse. 2 articles d'une ligne de 5 x 20,00 € HT à 20 %, proposés
     * 40,00 € + 8,00 €, dont la note écrit 5,00 € HT, proposent 1,00 € de
     * TVA ; écrit 45,00 €, 9,00 €. Un HT écrit égal à la proposition la
     * garde, TVA comprise : le retour de toute la ligne rend encore
     * exactement ce qu'il en reste.
     *
     * @param  array{prix_unitaire_ht: int, quantite: int, remise_pourcentage: int, taux_tva: int, montant_ht: int, montant_tva: int}  $ligne  la ligne achetée, telle qu'enregistrée : PU HT en millionièmes d'euro, montants en centimes, taux et remise en centièmes de point
     * @param  int  $quantiteRetournee  tous les articles retournés jusqu'ici, ce retour compris
     * @param  array{montant_ht: int, montant_tva: int}  $dejaRendu  ce que les retours précédents de la ligne ont rendu
     * @param  int|null  $htEcrit  le HT qu'écrit la note d'avoir du fournisseur pour ce retour, en centimes ; aucun, la proposition
     * @return array{montant_ht: int, montant_tva: int, montant_ttc: int, montant_ht_calcule: int}
     *
     * @throws InvalidArgumentException
     */
    public function retourDAchat(array $ligne, int $quantiteRetournee, array $dejaRendu, ?int $htEcrit = null): array
    {
        $taux = $this->taux($ligne, 'taux_tva');
        $quantite = $this->quantiteRendue($ligne, $quantiteRetournee);
        $htDeLaLigne = $this->entier($ligne, 'montant_ht');
        $tvaDeLaLigne = $this->entier($ligne, 'montant_tva');

        if ($quantite === $this->quantite($ligne)) {
            $retour = $this->moins($htDeLaLigne, $tvaDeLaLigne, $dejaRendu);
        } else {
            $ht = min($htDeLaLigne, $this->htDUnAchat($this->prixAuMillionieme($ligne), $quantite, $this->remise($ligne)));
            $retour = $this->moins($ht, min($tvaDeLaLigne, $this->tvaDuHt($ht, $taux)), $dejaRendu);
        }

        $htPropose = max(0, $retour['montant_ht']);
        $ht = $htPropose;
        $tva = max(0, $retour['montant_tva']);

        if ($htEcrit !== null && $htEcrit !== $htPropose) {
            $ht = $this->htEcrit(['montant_ht' => $htEcrit]);
            $htCumule = $this->borne($this->entier($dejaRendu, 'montant_ht') + $ht);
            $tva = min($ht, max(0, min($tvaDeLaLigne, $this->tvaDuHt($htCumule, $taux)) - $this->entier($dejaRendu, 'montant_tva')));
        }

        return ['montant_ht' => $ht, 'montant_tva' => $tva, 'montant_ttc' => $ht + $tva, 'montant_ht_calcule' => $htPropose];
    }

    /**
     * Répartit sur les lignes d'un taux, à leur proposition, ce que la TVA
     * retenue d'un retour au fournisseur (la note d'avoir du fournisseur)
     * a de plus ou de moins que la somme de ces propositions, comme sur une
     * facture d'achat (voir chiffrerUnAchat()) : au prorata de leur HT,
     * par cumul, dans leur ordre, sans qu'aucune ligne ne reçoive une TVA
     * négative ni plus grande que son HT. Les autres lignes ne changent
     * pas.
     *
     * @template TCle of array-key
     *
     * @param  array<TCle, array<string, mixed>>  $lignes  chacune avec taux, montant_ht et montant_tva, en centimes, taux en centièmes de point, dans leur ordre
     * @return array<TCle, array<string, mixed>> les mêmes lignes, sous les mêmes clés, avec leur montant_ttc
     *
     * @throws InvalidArgumentException
     */
    public function repartirUneTvaRetenue(array $lignes, int $taux, int $tvaRetenue): array
    {
        $base = 0;
        $proposee = 0;

        foreach ($lignes as $ligne) {
            if ($ligne['taux'] === $taux) {
                $base = $this->borne($base + $this->entier($ligne, 'montant_ht'));
                $proposee += $this->entier($ligne, 'montant_tva');
            }
        }

        $this->refuserUneTvaImpossible($taux, $base, $tvaRetenue);

        if ($tvaRetenue !== $proposee) {
            $lignes = $this->repartirLEcart($lignes, $taux, $base, $tvaRetenue - $proposee);
        }

        return array_map(fn (array $ligne): array => [...$ligne, 'montant_ttc' => $this->entier($ligne, 'montant_ht') + $this->entier($ligne, 'montant_tva')], $lignes);
    }

    /**
     * Le coût réel d'un article dont la facture du fournisseur écrit le
     * HT de la ligne (voir chiffrerUnAchat()) : ce HT ÷ la quantité, moins
     * l'escompte de la facture, au dix-millième d'euro comme le CMUP qu'il
     * alimente, arrondi une seule fois -- 10,00 € HT pour 3 articles font
     * 3,3333 € chacun (33333).
     *
     * @param  int  $htCentimes  le HT de la ligne, en centimes
     * @param  int  $escompteCentiemes  l'escompte de la facture, en centièmes de point
     * @return int en dix-millièmes d'euro
     *
     * @throws InvalidArgumentException
     */
    public function coutUnitaireDuHt(int $htCentimes, int $quantite, int $escompteCentiemes = 0): int
    {
        $quantite = $this->quantite(['quantite' => $quantite]);
        $ht = $this->entier(['montant_ht' => $htCentimes], 'montant_ht');

        // HT x (100 % - escompte) en centièmes de point, soit au plus 10^18
        // : en dix-millièmes d'euro, ÷ (100 x quantité).
        return $this->diviserArrondi($this->multiplier($ht, 10000 - $this->remise(['remise_pourcentage' => $escompteCentiemes])), $this->multiplier(100, $quantite));
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

    /**
     * Un prix HT au centime avec sa TVA : HT + HT x taux, la TVA arrondie
     * une fois au centime le plus proche -- 16,67 € HT à 20 % font
     * 20,00 € TTC. Le prix d'un service qui revient dans la TVA française,
     * quand une vente sans TVA française y revient (voir
     * ChoisitLaNatureDeLOperation).
     *
     * @param  int  $prixUnitaireHt  en centimes
     * @param  int  $tauxTva  en centièmes de point
     * @return int en centimes
     *
     * @throws InvalidArgumentException
     */
    public function prixUnitaireTtcDepuisHt(int $prixUnitaireHt, int $tauxTva): int
    {
        $ht = $this->entier(['prix_unitaire_ht' => $prixUnitaireHt], 'prix_unitaire_ht');

        return $ht + $this->tvaDuHt($ht, $this->taux(['taux_tva' => $tauxTva], 'taux_tva'));
    }

    /**
     * Le prix de vente TTC d'un prix HT exact -- un coût d'achat au
     * dix-millième plus un bénéfice (voir BaremePrixDeVente) : HT x (1 +
     * TVA), arrondi une seule fois, au multiple de $pasCentimes le plus
     * proche (au centime, ou à la dizaine d'euros pour un prix en X9).
     * C'est le seul prix arrondi, lors de l'enregistrement du produit.
     *
     * @param  int  $prixHt  en dix-millièmes d'euro
     * @param  int  $tauxTva  en centièmes de point
     * @return int en centimes
     *
     * @throws InvalidArgumentException
     */
    public function prixTtcArrondi(int $prixHt, int $tauxTva, int $pasCentimes = 1): int
    {
        $taux = $this->taux(['taux_tva' => $tauxTva], 'taux_tva');

        // Dix-millièmes d'euro x (100 % + taux) en centièmes de point :
        // 10^-8 €, soit 10^6 par centime.
        $prixExact = $this->multiplier($this->entier(['prix_ht' => $prixHt], 'prix_ht'), 10000 + $taux);

        return $this->multiplier($this->diviserArrondi($prixExact, $this->multiplier(1_000_000, $pasCentimes)), $pasCentimes);
    }

    /**
     * Valeur d'un stock au coût moyen (voir coutMoyenPondere()) : la
     * quantité x le CMUP au dix-millième, arrondie une seule fois au
     * centime -- 2 000 articles à 0,045 € valent 90,00 € HT, pas 2 000 x
     * 0,05 €. La TVA se calcule sur ce montant HT.
     *
     * @param  int  $coutMoyenHt  en dix-millièmes d'euro
     * @param  int  $tauxTva  en centièmes de point
     * @return array{montant_ht: int, montant_tva: int, montant_ttc: int} en centimes
     *
     * @throws InvalidArgumentException
     */
    public function valeurAuCoutMoyen(int $quantite, int $coutMoyenHt, int $tauxTva): array
    {
        $taux = $this->taux(['taux_tva' => $tauxTva], 'taux_tva');

        $htCentimes = $this->borne($this->diviserArrondi($this->multiplier($this->entier(['cout' => $coutMoyenHt], 'cout'), $quantite), 100));
        $tvaCentimes = $this->tvaDuHt($htCentimes, $taux);

        return [
            'montant_ht' => $htCentimes,
            'montant_tva' => $tvaCentimes,
            'montant_ttc' => $htCentimes + $tvaCentimes,
        ];
    }

    /**
     * Le coût réel d'un article acheté : son PU HT, tel que le fournisseur
     * l'écrit (six décimales au plus), moins la remise de la ligne, puis
     * l'escompte de la facture, au dix-millième d'euro comme le CMUP qu'il
     * alimente (voir coutMoyenPondere()) -- jamais arrondi au centime :
     * 1,18 € remisé de 15 % coûte 1,003 €, et 3 articles valent bien les
     * 3,01 € HT de la ligne, pas 3 x 1,00 € ; 0,1234 € coûte 0,1234 €.
     *
     * @param  int  $prixUnitaireHt  en millionièmes d'euro (voir enMillioniemes() et SaisieDecimale::enMillioniemes())
     * @param  int  $remise  la remise de la ligne, en centièmes de point
     * @param  int  $escompte  l'escompte de la facture, en centièmes de point
     * @return int en dix-millièmes d'euro
     *
     * @throws InvalidArgumentException
     */
    public function coutUnitaireNet(int $prixUnitaireHt, int $remise, int $escompte = 0): int
    {
        // (100 % - remise) x (100 % - escompte), en (centièmes de point)²,
        // soit au plus 10^8. Le coût vaut millionièmes x facteur / 10^10,
        // au dix-millième ; les millionièmes sont découpés autour de 10^10
        // pour que le produit ne déborde jamais, avec un seul arrondi.
        $facteur = (10000 - $this->remise(['remise_pourcentage' => $remise])) * (10000 - $this->remise(['remise_pourcentage' => $escompte]));

        return $this->multiplier(intdiv($prixUnitaireHt, 10_000_000_000), $facteur)
            + $this->diviserArrondi($this->multiplier($prixUnitaireHt % 10_000_000_000, $facteur), 10_000_000_000);
    }

    /**
     * Ce que rapporte un article : son prix de vente HT moins son coût
     * d'achat exact (voir coutUnitaireNet()), arrondi une seule fois au
     * centime -- 11,00 € - 1,005 € = 9,995 € -> 10,00 €.
     *
     * @param  int  $prixVenteHt  en centimes
     * @param  int  $coutUnitaireHt  en dix-millièmes d'euro
     * @return int en centimes
     *
     * @throws InvalidArgumentException
     */
    public function beneficeUnitaire(int $prixVenteHt, int $coutUnitaireHt): int
    {
        return $this->diviserArrondi(
            $this->multiplier($this->entier(['prix' => $prixVenteHt], 'prix'), 100) - $this->entier(['cout' => $coutUnitaireHt], 'cout'),
            100,
        );
    }

    /**
     * Le taux de marque d'un article : son bénéfice ÷ son prix de vente
     * HT, en centièmes de point, arrondi une seule fois au plus proche --
     * 12,50 € de bénéfice sur 20,83 € HT font 60,01 % (6001). Un bénéfice
     * négatif donne un taux négatif ; sans prix de vente, aucun taux
     * (null).
     *
     * @param  int  $benefice  en centimes
     * @param  int  $prixVenteHt  en centimes
     */
    public function tauxDeMarque(int $benefice, int $prixVenteHt): ?int
    {
        return $prixVenteHt <= 0 ? null : $this->diviserArrondi($this->multiplier($benefice, 10000), $prixVenteHt);
    }

    /**
     * Coût unitaire moyen de lots (quantité, coût unitaire) pondéré par
     * leurs quantités, au dix-millième d'euro : Σ(quantité x coût) /
     * Σ quantité. Des lots de coût nul (marchandise offerte) coûtent 0 ;
     * des lots qui ne pèsent rien (aucun article) n'ont pas de coût moyen :
     * null. Une quantité ou un coût négatif n'a pas de sens.
     *
     * @param  iterable<array{quantite: int, cout_unitaire: int}>  $lots  chaque coût en dix-millièmes d'euro
     * @return int|null en dix-millièmes d'euro
     *
     * @throws InvalidArgumentException
     */
    public function coutMoyenPondere(iterable $lots): ?int
    {
        $quantiteTotale = 0;
        $valeurTotaleDixMilliemes = 0;

        foreach ($lots as $lot) {
            $coutDixMilliemes = $this->entier($lot, 'cout_unitaire');

            if ($lot['quantite'] < 0 || $coutDixMilliemes < 0) {
                throw new InvalidArgumentException('Un lot de stock ne peut avoir ni quantité ni coût négatif.');
            }

            $quantiteTotale += $lot['quantite'];
            $valeurTotaleDixMilliemes += $this->multiplier($coutDixMilliemes, $lot['quantite']);
        }

        if ($quantiteTotale === 0) {
            return null;
        }

        return $this->diviserArrondi($valeurTotaleDixMilliemes, $quantiteTotale);
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
     * @param  int  $coutMoyen  en dix-millièmes d'euro
     * @param  int  $coutUnitaire  en dix-millièmes d'euro
     * @return int|null en dix-millièmes d'euro
     *
     * @throws InvalidArgumentException
     */
    public function coutMoyenApresUneSortie(int $stock, int $coutMoyen, int $quantite, int $coutUnitaire): ?int
    {
        $coutMoyenDixMilliemes = $this->entier(['cout' => $coutMoyen], 'cout');
        $coutUnitaireDixMilliemes = $this->entier(['cout' => $coutUnitaire], 'cout');

        if ($stock < 0 || $quantite < 0 || $coutMoyenDixMilliemes < 0 || $coutUnitaireDixMilliemes < 0) {
            throw new InvalidArgumentException('Un lot de stock ne peut avoir ni quantité ni coût négatif.');
        }

        $reste = $stock - $quantite;

        if ($reste <= 0) {
            return null;
        }

        $valeurRestanteDixMilliemes = $this->multiplier($coutMoyenDixMilliemes, $stock) - $this->multiplier($coutUnitaireDixMilliemes, $quantite);

        // La valeur d'un stock ne descend jamais sous 0 €.
        return $this->diviserArrondi(max(0, $valeurRestanteDixMilliemes), $reste);
    }

    /**
     * Les sommes des HT, des TVA et des TTC de ces lignes, tableaux ou
     * lignes enregistrées, en centimes : chaque montant est écrit comme sa
     * colonne (« 41.67 », voir enCentiemes()), et la somme se fait en
     * entiers, jamais en flottants ni par la base. Pour les enregistrer
     * dans les colonnes d'un document, voir enDecimaux().
     *
     * @param  iterable<array{montant_ht: mixed, montant_tva: mixed, montant_ttc: mixed}|\ArrayAccess<string, mixed>>  $lignes
     * @return array{total_ht: int, total_tva: int, total_ttc: int}
     *
     * @throws UnexpectedValueException un montant qui n'est pas une valeur simple
     * @throws InvalidArgumentException un montant qui n'est pas écrit comme sa colonne
     */
    public function totaliser(iterable $lignes): array
    {
        $totalHtCentimes = 0;
        $totalTvaCentimes = 0;
        $totalTtcCentimes = 0;

        foreach ($lignes as $ligne) {
            $totalHtCentimes = $this->borne($totalHtCentimes + $this->enCentiemes(ValeurSimple::simple($ligne['montant_ht'])));
            $totalTvaCentimes = $this->borne($totalTvaCentimes + $this->enCentiemes(ValeurSimple::simple($ligne['montant_tva'])));
            $totalTtcCentimes = $this->borne($totalTtcCentimes + $this->enCentiemes(ValeurSimple::simple($ligne['montant_ttc'])));
        }

        return [
            'total_ht' => $totalHtCentimes,
            'total_tva' => $totalTvaCentimes,
            'total_ttc' => $totalTtcCentimes,
        ];
    }

    /**
     * Cumul de documents déjà totalisés (tickets en attente de clôture,
     * chiffre d'affaires, relevé « Fermer ma caisse ») : les mêmes sommes
     * séparées des HT, des TVA et des TTC qu'entre les lignes d'un
     * document, en centimes.
     *
     * @param  iterable<array{total_ht: string, total_tva: string, total_ttc: string}|\ArrayAccess<string, mixed>>  $documents
     * @return array{total_ht: int, total_tva: int, total_ttc: int}
     *
     * @throws UnexpectedValueException
     * @throws InvalidArgumentException
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
     * d'une journée de caisse moins ses avoirs), en centimes, séparément
     * pour les HT, les TVA et les TTC.
     *
     * @param  array{total_ht: int, total_tva: int, total_ttc: int}  $totaux
     * @param  array{total_ht: int, total_tva: int, total_ttc: int}  $totauxDeduits
     * @return array{total_ht: int, total_tva: int, total_ttc: int}
     *
     * @throws InvalidArgumentException
     */
    public function deduire(array $totaux, array $totauxDeduits): array
    {
        return [
            'total_ht' => $this->borne($totaux['total_ht'] - $totauxDeduits['total_ht']),
            'total_tva' => $this->borne($totaux['total_tva'] - $totauxDeduits['total_tva']),
            'total_ttc' => $this->borne($totaux['total_ttc'] - $totauxDeduits['total_ttc']),
        ];
    }

    /**
     * Somme exacte de montants écrits comme leurs colonnes (paiements,
     * crédits d'avoir...), en centimes : chaque montant lu par
     * enCentiemes(), rien ou un texte vide comptant pour zéro -- jamais une
     * somme de flottants.
     *
     * @param  iterable<int|string|null>  $montants
     *
     * @throws InvalidArgumentException
     */
    public function sommer(iterable $montants): int
    {
        $totalCentimes = 0;

        foreach ($montants as $montant) {
            if ($montant !== null && $montant !== '') {
                $totalCentimes = $this->borne($totalCentimes + $this->enCentiemes($montant));
            }
        }

        return $totalCentimes;
    }

    /**
     * Des montants en centimes écrits comme leurs colonnes (voir
     * enDecimal()), sous les mêmes clés : les totaux d'un document à
     * enregistrer (voir totaliser()).
     *
     * @template TCle of array-key
     *
     * @param  array<TCle, int>  $centimes
     * @return array<TCle, numeric-string>
     *
     * @throws InvalidArgumentException
     */
    public function enDecimaux(array $centimes): array
    {
        return array_map($this->enDecimal(...), $centimes);
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
     * @param  mixed  $valeur  une chaîne décimale ou un entier ; toute autre valeur est refusée
     *
     * @throws InvalidArgumentException
     */
    public function enCentiemes(mixed $valeur): int
    {
        if (! is_int($valeur)) {
            return $this->lireUneEcriture($valeur, 2, 'Montant illisible : une chaîne décimale exacte (deux décimales au plus) ou un entier est attendu.');
        }

        return $this->borne($this->multiplier($valeur, 100));
    }

    /**
     * L'inverse d'enCentiemes() : 12000 s'écrit « 120.00 », -5 s'écrit
     * « -0.05 », comme le cast decimal:2.
     *
     * @return numeric-string
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
        [, $decimales] = explode('.', $this->enDecimal($centiemes));

        return self::ecrireEnEuros($centiemes, 100, $decimales);
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
     * @param  mixed  $valeur  une chaîne décimale ou un entier ; toute autre valeur est refusée
     *
     * @throws InvalidArgumentException
     */
    public function enMillioniemes(mixed $valeur): int
    {
        if (! is_int($valeur)) {
            return $this->lireUneEcriture($valeur, 6, 'Prix illisible : une chaîne décimale exacte (six décimales au plus) ou un entier est attendu.');
        }

        $millioniemes = $this->multiplier($valeur, 1_000_000);

        if (abs($millioniemes) >= self::MILLIONIEMES_MAXIMUM) {
            throw new InvalidArgumentException('Montant hors limites.');
        }

        return $millioniemes;
    }

    /**
     * L'inverse d'enMillioniemes(), comme le cast decimal:6 : 833333
     * s'écrit « 0.833333 », 10000000 « 10.000000 ».
     *
     * @return numeric-string
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
     * Un coût enregistré au dix-millième d'euro (decimal(14,4), lu en
     * chaîne par le cast decimal:4 : le coût moyen d'un produit, le coût
     * d'un mouvement de stock), en dix-millièmes entiers : « 3.3333 » vaut
     * 33333, un entier compte des euros (2 vaut 20000). Mêmes règles
     * qu'enCentiemes() : une écriture décimale exacte, quatre décimales au
     * plus, jamais un flottant, et la même borne, en dix-millièmes.
     *
     * @param  mixed  $valeur  une chaîne décimale ou un entier ; toute autre valeur est refusée
     *
     * @throws InvalidArgumentException
     */
    public function enDixMilliemes(mixed $valeur): int
    {
        if (! is_int($valeur)) {
            return $this->lireUneEcriture($valeur, 4, 'Coût illisible : une chaîne décimale exacte (quatre décimales au plus) ou un entier est attendu.');
        }

        return $this->borne($this->multiplier($valeur, 10000));
    }

    /**
     * L'inverse d'enDixMilliemes(), comme le cast decimal:4 : 33333
     * s'écrit « 3.3333 », 20000 « 2.0000 ».
     *
     * @return numeric-string
     *
     * @throws InvalidArgumentException
     */
    public function enDecimalAuDixMillieme(int $dixMilliemes): string
    {
        $this->borne($dixMilliemes);

        return sprintf('%s%d.%04d', $dixMilliemes < 0 ? '-' : '', intdiv(abs($dixMilliemes), 10000), abs($dixMilliemes) % 10000);
    }

    /**
     * Un prix unitaire en millionièmes, tel qu'il s'affiche (décision de
     * l'éditeur du 25/10/2026), en centimes : arrondi une fois au centime
     * le plus proche, un demi-centime exact au centime supérieur --
     * 54,166667 € s'affiche 54,17 € ; 8,325 €, 8,33 € ; 0,833333 €, 0,83 €.
     * Seul l'affichage s'arrondit : le prix enregistré ne change pas, et
     * aucun montant ne se calcule sur ce prix affiché (voir
     * MentionsFiscales::calcul(), qui explique l'écart).
     *
     * @throws InvalidArgumentException
     */
    public function prixUnitaireAuCentime(int $millioniemes): int
    {
        if (abs($millioniemes) >= self::MILLIONIEMES_MAXIMUM) {
            throw new InvalidArgumentException('Montant hors limites.');
        }

        return $this->diviserArrondi($millioniemes, 10_000);
    }

    /**
     * La mise en forme de tout prix unitaire de vente que montre en lecture
     * un document, une fiche, une liste, une aide, un message ou la caisse,
     * PU TTC, prix de vente, prix de référence, PU HT d'un ticket ou d'un
     * document à un particulier, et du coût moyen d'un produit : arrondi au
     * centime (voir prixUnitaireAuCentime()), écrit comme un montant (voir
     * enEuros()) : « 54,17 € », « 0,83 € », « 1 000,00 € ». Un prix
     * enregistré au centime s'y écrit tel quel. Un champ où le prix ne se
     * saisit pas le montre de même au centime (voir
     * prixUnitaireDansUnChamp()) ; un champ où il se saisit, tel qu'il est
     * enregistré. Le PU HT d'un document qui le montre (facture, avoir sur
     * facture ou devis à un professionnel, vente sans TVA française) et un
     * prix d'achat s'affichent tels qu'ils sont enregistrés (voir
     * prixUnitaireExact()).
     *
     * @throws InvalidArgumentException
     */
    public function prixUnitaireAffiche(int $millioniemes): string
    {
        return $this->enEuros($this->prixUnitaireAuCentime($millioniemes));
    }

    /**
     * Un prix unitaire en millionièmes écrit tel qu'il est enregistré, sans
     * zéro inutile au-delà du centime, comme le relit sa colonne (voir
     * PrixDAchat) et le montre un champ : « 0.1234 », « 12.50 »,
     * « 8.333333 ». Le PU HT d'une ligne d'achat ou un coût d'inventaire
     * d'ouverture, où il se saisit (décision de l'éditeur du 02/11/2026,
     * T2), et le PU HT d'une ligne de vente dans le formulaire d'un
     * document qui le montre, où il ne se saisit pas (décision de l'éditeur
     * du 09/11/2026, voir prixUnitaireExact()).
     *
     * @return numeric-string
     *
     * @throws InvalidArgumentException
     */
    public function prixUnitaireExactEnDecimal(int $millioniemes): string
    {
        $decimal = $this->enDecimalAuMillionieme($millioniemes);
        [$entiers, $decimales] = explode('.', $decimal);

        return $entiers.'.'.str_pad(rtrim($decimales, '0'), 2, '0');
    }

    /**
     * La seule mise en forme d'un prix unitaire montré tel qu'il est
     * enregistré, jusqu'à six décimales, sans zéro inutile au-delà du
     * centime, écrit comme un montant : « 8,333333 € », « 8,325 € »,
     * « 0,825 € », « 1 040,833333 € », « 45,00 € ». Il ne s'arrondit jamais
     * au centime : la quantité x ce prix, remise déduite, retombe sur le
     * total HT de la ligne à moins de deux centimes près (voir
     * prixUnitaireHtExact()). C'est le PU HT d'une ligne de vente sur les
     * documents qui le montrent, leur PDF, leur fiche et les lignes de leur
     * formulaire -- factures, avoirs sur facture et devis à un
     * professionnel, ventes sans TVA française (décision de l'éditeur du
     * 09/11/2026) --, le même prix que la facture électronique transmet ; et
     * le PU HT d'un achat ou le coût d'un inventaire d'ouverture dans les
     * fiches, les listes, les aides et les messages des achats (décision de
     * l'éditeur du 02/11/2026, T2) : 1 000 x 0,1234 € font bien 123,40 € HT.
     *
     * @throws InvalidArgumentException
     */
    public function prixUnitaireExact(int $millioniemes): string
    {
        [, $decimales] = explode('.', $this->prixUnitaireExactEnDecimal($millioniemes));

        return self::ecrireEnEuros($millioniemes, 1_000_000, $decimales);
    }

    /**
     * Un prix unitaire tel que le montre un champ où il ne se saisit pas :
     * le PU HT d'une ligne de vente, simple conversion de son PU TTC saisi,
     * ou le coût moyen d'un produit, que seuls les achats, les retours et
     * les inventaires font évoluer. Arrondi au centime comme tout prix
     * unitaire montré en lecture (voir prixUnitaireAuCentime()), il s'écrit
     * comme une colonne au centime (voir enDecimal()) : « 54.17 » pour
     * 54,166667 €, « 0.83 », « 12.00 ».
     *
     * @throws InvalidArgumentException
     */
    public function prixUnitaireDansUnChamp(int $millioniemes): string
    {
        return $this->enDecimal($this->prixUnitaireAuCentime($millioniemes));
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
     * Le HT que le calcul propose pour une ligne d'achat (voir htDUnAchat()),
     * pour la montrer à côté du HT qu'écrit la facture du fournisseur.
     *
     * @param  int  $prixMillioniemes  le PU HT, en millionièmes d'euro
     * @param  int  $remiseCentiemes  en centièmes de point
     * @return int en centimes
     *
     * @throws InvalidArgumentException
     */
    public function htProposeDUneLigneDAchat(int $prixMillioniemes, int $quantite, int $remiseCentiemes): int
    {
        $ligne = ['prix_unitaire_ht' => $prixMillioniemes, 'quantite' => $quantite, 'remise_pourcentage' => $remiseCentiemes];

        return $this->htDUnAchat($this->prixAuMillionieme($ligne), $this->quantite($ligne), $this->remise($ligne));
    }

    /**
     * Le HT que le calcul propose pour une ligne d'achat : quantité x PU HT
     * (en millionièmes d'euro), remise déduite, arrondi une fois au centime
     * le plus proche ; un demi-centime exact s'arrondit au centime
     * inférieur, ce qui revient, pour un PU au centime, à déduire la remise
     * arrondie au centime supérieur, comme sur une vente (voir
     * montantNetCentimes()) : 3 x 1,18 € remisés de 15 % font 3,54 € - 0,53
     * € = 3,01 € ; 1 000 x 0,1234 € font 123,40 €.
     *
     * Le brut se découpe en centimes entiers et en millionièmes restants,
     * et le net en centimes entiers et en cent-millionièmes de centime, pour
     * que les produits ne débordent jamais.
     *
     * @throws InvalidArgumentException
     */
    private function htDUnAchat(int $prixMillioniemes, int $quantite, int $remiseCentiemes): int
    {
        $brut = $this->multiplier($prixMillioniemes, $quantite);

        if (abs($brut) >= self::MILLIONIEMES_MAXIMUM) {
            throw new InvalidArgumentException('Montant hors limites.');
        }

        // Le net vaut brut x (100 % - remise) ÷ 10^8 centimes : les centimes
        // entiers du brut x le facteur (en dix-millièmes de centime), puis
        // le reste, en cent-millionièmes de centime, moins d'un centime.
        $facteur = 10000 - $remiseCentiemes;
        $centimesRemises = $this->multiplier(intdiv($brut, 10000), $facteur);
        $fraction = ($centimesRemises % 10000) * 10000 + ($brut % 10000) * $facteur;
        $net = intdiv($centimesRemises, 10000) + intdiv($fraction, 100_000_000);

        return $this->borne($net + ($fraction % 100_000_000 > 50_000_000 ? 1 : 0));
    }

    /**
     * Le PU HT d'une ligne d'achat en millionièmes d'euro : jamais négatif.
     * La borne des prix unitaires se contrôle sur le HT de la ligne (voir
     * htDUnAchat()), où il sert toujours : quantité x PU, au moins le PU.
     *
     * @param  array<string, mixed>  $ligne
     *
     * @throws InvalidArgumentException
     */
    private function prixAuMillionieme(array $ligne): int
    {
        $prix = $ligne['prix_unitaire_ht'] ?? null;

        if (! is_int($prix)) {
            throw new InvalidArgumentException(self::NOMBRE_A_VIRGULE);
        }

        if ($prix < 0) {
            throw new InvalidArgumentException('Un prix unitaire ne peut pas être négatif.');
        }

        return $prix;
    }

    /**
     * Le HT d'une ligne d'achat qu'écrit la facture du fournisseur, en
     * centimes : jamais négatif.
     *
     * @param  array<string, mixed>  $ligne
     *
     * @throws InvalidArgumentException
     */
    private function htEcrit(array $ligne): int
    {
        $ht = $this->entier($ligne, 'montant_ht');

        if ($ht < 0) {
            throw new InvalidArgumentException(self::REFUS_HT_NEGATIF);
        }

        return $ht;
    }

    /**
     * Refuse la TVA d'un taux qu'aucune facture valable ne porte : négative,
     * non nulle à 0 %, ou plus grande que sa base.
     *
     * @throws InvalidArgumentException
     */
    private function refuserUneTvaImpossible(int $taux, int $base, int $tva): void
    {
        if ($tva < 0) {
            throw new InvalidArgumentException(sprintf(self::REFUS_TVA_NEGATIVE, self::tauxEnTexte($taux)));
        }

        if ($taux === 0 && $tva !== 0) {
            throw new InvalidArgumentException(self::REFUS_TVA_A_ZERO);
        }

        if ($tva > $base) {
            throw new InvalidArgumentException(sprintf(self::REFUS_TVA_AU_DELA_DE_LA_BASE, self::tauxEnTexte($taux), $this->enEuros($tva), $this->enEuros($base)));
        }
    }

    /**
     * Ajoute aux lignes de ce taux l'écart entre sa TVA retenue et sa TVA
     * proposée, au prorata de leur HT, par cumul, dans leur ordre : chaque
     * ligne reçoit la part arrondie du cumul de leurs HT jusqu'à elle, moins
     * celle des lignes précédentes. Aucune ligne ne reçoit une TVA négative
     * ni plus grande que son HT : ce qu'elle ne peut pas recevoir se reporte
     * sur la ligne suivante, puis, après la dernière, sur les précédentes,
     * en remontant. La TVA retenue étant comprise entre 0 et la base, tout
     * l'écart se place.
     *
     * @template TCle of array-key
     *
     * @param  array<TCle, array<string, mixed>>  $lignes  chacune avec taux, montant_ht et montant_tva, dans leur ordre
     * @return array<TCle, array<string, mixed>>
     *
     * @throws InvalidArgumentException
     */
    private function repartirLEcart(array $lignes, int $taux, int $base, int $ecart): array
    {
        $rangs = array_keys(array_filter($lignes, fn (array $ligne): bool => $ligne['taux'] === $taux));
        $cumulHt = 0;
        $partPrecedente = 0;
        $report = 0;

        foreach ($rangs as $rang) {
            $ht = $this->entier($lignes[$rang], 'montant_ht');
            $cumulHt += $ht;
            // Une base nulle n'a que des lignes à 0 € HT : aucune part.
            $part = $this->diviserArrondi($this->multiplier($ecart, $cumulHt), max($base, 1));
            $voulue = $this->entier($lignes[$rang], 'montant_tva') + $part - $partPrecedente + $report;
            $partPrecedente = $part;
            $tva = max(0, min($ht, $voulue));
            $report = $voulue - $tva;
            $lignes[$rang]['montant_tva'] = $tva;
        }

        foreach (array_reverse($rangs) as $rang) {
            $tvaPlacee = $this->entier($lignes[$rang], 'montant_tva');
            $tva = max(0, min($this->entier($lignes[$rang], 'montant_ht'), $tvaPlacee + $report));
            $report -= $tva - $tvaPlacee;
            $lignes[$rang]['montant_tva'] = $tva;
        }

        return $lignes;
    }

    /**
     * Un taux ou une remise en centièmes de point tel qu'il s'écrit à
     * l'écran et sur les documents, sans zéro inutile : « 20 % »,
     * « 5,5 % », « 2,1 % », « 0,05 % ».
     */
    public static function tauxEnTexte(int $taux): string
    {
        return str_replace('.', ',', self::centiemesDansUnChamp($taux)).' %';
    }

    /**
     * Un nombre en centièmes -- un taux ou une remise en centièmes de
     * point, un montant du barème des prix de vente en centimes -- tel que
     * l'écrit un champ où il se saisit, sans zéro inutile, avec un point :
     * « 20 », « 5.5 », « 0 », « 12.34 » ; comme le relit la saisie (voir
     * SaisieDecimale::enCentiemes()).
     */
    public static function centiemesDansUnChamp(int $centiemes): string
    {
        $decimales = rtrim(sprintf('%02d', abs($centiemes) % 100), '0');

        return ($centiemes < 0 ? '-' : '').intdiv(abs($centiemes), 100).($decimales === '' ? '' : '.'.$decimales);
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
     * Une écriture décimale exacte, en unités de 10^-$decimales (voir
     * enCentiemes() et enMillioniemes()) : des chiffres, $decimales
     * décimales au plus après un point, un « - » facultatif, aucun espace
     * ni signe « + » ; ses chiffres se lisent bout à bout, signe compris,
     * sans calcul ni arrondi (« -12.5 » vaut -1250 centièmes). Douze
     * chiffres entiers au plus, des zéros de tête ne comptant pas : treize
     * atteignent la borne de la classe, en centièmes comme en
     * millionièmes, et un entier plus long saturerait en silence.
     *
     * @throws InvalidArgumentException
     */
    private function lireUneEcriture(mixed $valeur, int $decimales, string $illisible): int
    {
        if (! is_string($valeur) || preg_match('/^(-?)(\d+)(?:\.(\d{1,'.$decimales.'}))?$/D', $valeur, $morceaux) !== 1) {
            throw new InvalidArgumentException($illisible);
        }

        $unites = ltrim($morceaux[2], '0');

        if (strlen($unites) > 12) {
            throw new InvalidArgumentException('Montant hors limites.');
        }

        return (int) ($morceaux[1].$unites.str_pad($morceaux[3] ?? '', $decimales, '0'));
    }

    /**
     * Un montant tel qu'un écran l'écrit (voir enEuros() et
     * prixUnitaireExact()) : son signe, ses euros entiers, les milliers
     * séparés par une espace, une virgule, ses décimales telles qu'elles
     * s'écrivent, puis « € ».
     */
    private static function ecrireEnEuros(int $valeur, int $unitesParEuro, string $decimales): string
    {
        return ($valeur < 0 ? '-' : '').number_format(intdiv(abs($valeur), $unitesParEuro), 0, ',', ' ').','.$decimales.' €';
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
     * flottant (approché) au lieu d'échouer -- il est refusé. Le produit
     * passe par array_product(), qui calcule comme « * » et dit, par son
     * type (int|float), qu'il peut déborder.
     */
    private function multiplier(int $a, int $b): int
    {
        $produit = array_product([$a, $b]);

        if (! is_int($produit)) {
            throw new InvalidArgumentException('Montant hors limites.');
        }

        return $produit;
    }

    /**
     * Division entière arrondie au plus proche (demi vers l'extérieur),
     * sans jamais passer par un flottant.
     */
    private function diviserArrondi(int $numerateur, int $denominateur): int
    {
        $quotient = intdiv($numerateur, $denominateur);

        if (2 * abs($numerateur % $denominateur) >= $denominateur) {
            $quotient += $numerateur <=> 0;
        }

        return $quotient;
    }
}
