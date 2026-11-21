<?php

namespace App\Domain\TableauDeBord\Support;

use InvalidArgumentException;
use OverflowException;

/**
 * Le calcul pur de la carte « Écart des prix d'achat » du tableau de bord
 * (le dix-neuvième graphique ; maquette validée par l'éditeur le
 * 21/11/2026, décision D76 de Claude pour ce que la maquette ne couvre
 * pas) : aucune base de données, aucun Eloquent, que des entiers de
 * centimes.
 *
 * La carte reçoit, pour chaque produit, la liste de ses achats dans l'ordre
 * où elle est donnée (le « premier achat » est le premier de la liste : on
 * ne trie jamais ici) : le mois `k` (de 0 à 11, 0 étant le premier mois de
 * la période), le jour `j` du mois (qui ne sert qu'à l'affichage), la
 * quantité `q` et le prix unitaire HT `c` en centimes.
 *
 * Un total par mois : pour chaque produit acheté dans le mois, son premier
 * achat du mois est le prix de départ du mois ; chaque achat suivant du
 * même produit dans le mois est comparé à lui (écart = q × (c − c du
 * départ), même quand l'achat est au même prix : il compte alors comme
 * achat comparé, sans écart). Un seul total pour les 12 mois, calculé à
 * part et jamais la somme des mois : le premier achat du produit dans la
 * période est le prix de départ de l'année, et chaque achat suivant, de
 * n'importe quel mois, est comparé à lui. Hausses = somme des écarts
 * positifs, baisses = somme des écarts négatifs en valeur positive, écart
 * = hausses − baisses. Les produits sans écart (achetés une seule fois, ou
 * toujours au même prix) ne comptent dans aucun total de la période ; un
 * produit sans aucun achat n'est compté nulle part.
 *
 * Une entrée refusée lève une exception : un achat dont `k`, `j`, `q` ou
 * `c` n'est pas un entier de valeur absolue au plus 9 007 199 254 740 991,
 * un mois `k` hors de 0 à 11, une période qui n'a pas 12 mois « AAAA-MM » ;
 * et tout dépassement d'entier (jamais un nombre à virgule silencieux).
 *
 * @phpstan-type Achat array{k: int, j: int, q: int, c: int}
 * @phpstan-type ProduitAchete array{nom: string, achats: array<int, array<string, mixed>>}
 * @phpstan-type AchatCompare array{i: int, e: Achat, ref: Achat, ecart: int, base: int, cout: int}
 * @phpstan-type PremierAchat array{i: int, e: Achat}
 * @phpstan-type CalculDuMois array{k: int, m: string, base: int, cout: int, ecart: int, hausses: int, baisses: int, n: int, sans: int, pas: list<AchatCompare>, premiers: list<PremierAchat>}
 * @phpstan-type AchatDeLAnnee array{i: int, e: Achat, ref: Achat, ecart: int}
 * @phpstan-type ProduitDeLaPeriode array{i: int, nom: string, ref: Achat, pas: list<AchatDeLAnnee>, affiche: bool, hausses: int, baisses: int, net: int, qte: int, montant: int}
 * @phpstan-type CalculDeLaPeriode array{hausses: int, baisses: int, ecart: int, n: int, uneFois: int, visibles: int, memePrix: int, lignes: list<ProduitDeLaPeriode>, qte: int, montant: int, base: int, egaux: array{n: int, qte: int, montant: int}}
 * @phpstan-type Calcul array{mois: list<string>, courts: list<string>, periode: string, produits: list<string>, achatsDeLAnnee: list<AchatDeLAnnee>, calculs: list<CalculDuMois>, sommeDesMois: array{hausses: int, baisses: int, ecart: int, n: int}, calculDeLaPeriode: CalculDeLaPeriode}
 */
final class EcartDesPrixDAchat
{
    /** Le nombre de mois de la période, toujours douze. */
    public const NOMBRE_DE_MOIS = 12;

    /**
     * Le calcul des douze mois et de la période.
     *
     * @param  array<int, ProduitAchete>  $produits  dans l'ordre des produits, chaque liste d'achats dans l'ordre donné
     * @param  array<int, string>  $mois  les douze mois « AAAA-MM » de la période
     * @return Calcul
     */
    public static function calculer(array $produits, array $mois): array
    {
        $mois = array_values($mois);

        if (count($mois) !== self::NOMBRE_DE_MOIS) {
            throw new InvalidArgumentException('La période compte douze mois.');
        }

        $libelles = array_map(Mois::libelle(...), $mois);
        $courts = array_map(Mois::nomCourt(...), $mois);
        /** @var list<string> $noms */
        $noms = [];
        /** @var array<int, list<PremierAchat>> $premiersParMois */
        $premiersParMois = array_fill(0, self::NOMBRE_DE_MOIS, []);
        /** @var array<int, list<AchatCompare>> $pasParMois */
        $pasParMois = array_fill(0, self::NOMBRE_DE_MOIS, []);
        /** @var list<AchatDeLAnnee> $achatsDeLAnnee */
        $achatsDeLAnnee = [];
        /** @var list<ProduitDeLaPeriode> $lignesDeLaPeriode */
        $lignesDeLaPeriode = [];
        $hausses = 0;
        $baisses = 0;
        $produitsAvecEcart = 0;
        $uneFois = 0;
        $quantites = 0;
        $montants = 0;
        $egaux = ['n' => 0, 'qte' => 0, 'montant' => 0];

        foreach (array_values($produits) as $i => $produit) {
            $noms[] = $produit['nom'];
            $achats = self::lireLesAchats($produit['achats']);

            foreach (self::parMois($achats) as $k => $duMois) {
                $reference = $duMois[0];
                $premiersParMois[$k][] = ['i' => $i, 'e' => $reference];

                foreach (array_slice($duMois, 1) as $achat) {
                    $pasParMois[$k][] = [
                        'i' => $i,
                        'e' => $achat,
                        'ref' => $reference,
                        'ecart' => self::multiplier($achat['q'], $achat['c'] - $reference['c']),
                        'base' => self::multiplier($achat['q'], $reference['c']),
                        'cout' => self::multiplier($achat['q'], $achat['c']),
                    ];
                }
            }

            if ($achats === []) {
                continue;
            }

            $depart = $achats[0];
            $pasDuProduit = [];

            foreach (array_slice($achats, 1) as $achat) {
                $pasDuProduit[] = ['i' => $i, 'e' => $achat, 'ref' => $depart, 'ecart' => self::multiplier($achat['q'], $achat['c'] - $depart['c'])];
            }

            array_push($achatsDeLAnnee, ...$pasDuProduit);

            if ($pasDuProduit === []) {
                $uneFois++;

                continue;
            }

            $hausse = 0;
            $baisse = 0;
            $affiches = [];

            foreach ($pasDuProduit as $pas) {
                if ($pas['ecart'] > 0) {
                    $hausse = self::ajouter($hausse, $pas['ecart']);
                } else {
                    $baisse = self::soustraire($baisse, $pas['ecart']);
                }

                if ($pas['ecart'] !== 0) {
                    $affiches[] = $pas['e'];
                }
            }

            $hausses = self::ajouter($hausses, $hausse);
            $baisses = self::ajouter($baisses, $baisse);
            $produitsAvecEcart++;

            $quantiteDuProduit = 0;
            $montantDuProduit = 0;

            if ($affiches !== []) {
                foreach ([$depart, ...$affiches] as $achat) {
                    $quantiteDuProduit = self::ajouter($quantiteDuProduit, $achat['q']);
                    $montantDuProduit = self::ajouter($montantDuProduit, self::multiplier($achat['q'], $achat['c']));
                }
            }

            if ($affiches !== []) {
                foreach ($pasDuProduit as $pas) {
                    if ($pas['ecart'] === 0) {
                        $egaux['n']++;
                        $egaux['qte'] = self::ajouter($egaux['qte'], $pas['e']['q']);
                        $egaux['montant'] = self::ajouter($egaux['montant'], self::multiplier($pas['e']['q'], $pas['e']['c']));
                    }
                }
            }

            $quantites = self::ajouter($quantites, $quantiteDuProduit);
            $montants = self::ajouter($montants, $montantDuProduit);
            $lignesDeLaPeriode[] = [
                'i' => $i,
                'nom' => $produit['nom'],
                'ref' => $depart,
                'pas' => $pasDuProduit,
                'affiche' => $affiches !== [],
                'hausses' => $hausse,
                'baisses' => $baisse,
                'net' => self::soustraire($hausse, $baisse),
                'qte' => $quantiteDuProduit,
                'montant' => $montantDuProduit,
            ];
        }

        $calculs = [];

        for ($k = 0; $k < self::NOMBRE_DE_MOIS; $k++) {
            $calculs[] = self::calculerLeMois($k, $libelles[$k], $premiersParMois[$k], $pasParMois[$k]);
        }

        $somme = ['hausses' => 0, 'baisses' => 0, 'ecart' => 0, 'n' => 0];

        foreach ($calculs as $mois) {
            $somme['hausses'] = self::ajouter($somme['hausses'], $mois['hausses']);
            $somme['baisses'] = self::ajouter($somme['baisses'], $mois['baisses']);
            $somme['ecart'] = self::ajouter($somme['ecart'], $mois['ecart']);
            $somme['n'] += $mois['n'];
        }

        $visibles = count(array_filter($lignesDeLaPeriode, fn (array $ligne): bool => $ligne['affiche']));

        return [
            'mois' => $libelles,
            'courts' => $courts,
            'periode' => $libelles[0].' à '.$libelles[self::NOMBRE_DE_MOIS - 1],
            'produits' => $noms,
            'achatsDeLAnnee' => $achatsDeLAnnee,
            'calculs' => $calculs,
            'sommeDesMois' => $somme,
            'calculDeLaPeriode' => [
                'hausses' => $hausses,
                'baisses' => $baisses,
                'ecart' => self::soustraire($hausses, $baisses),
                'n' => $produitsAvecEcart,
                'uneFois' => $uneFois,
                'visibles' => $visibles,
                'memePrix' => $produitsAvecEcart - $visibles,
                'lignes' => $lignesDeLaPeriode,
                'qte' => $quantites,
                'montant' => $montants,
                'base' => self::soustraire($montants, self::soustraire($hausses, $baisses)),
                'egaux' => $egaux,
            ],
        ];
    }

    /**
     * Le calcul d'un mois : la base (ce que les achats comparés auraient
     * coûté au prix de départ), le coût, l'écart, les hausses, les baisses
     * et les nombres d'achats comparés et de produits achetés.
     *
     * @param  list<PremierAchat>  $premiers
     * @param  list<AchatCompare>  $pas
     * @return CalculDuMois
     */
    private static function calculerLeMois(int $k, string $libelle, array $premiers, array $pas): array
    {
        $base = 0;
        $cout = 0;
        $hausses = 0;
        $baisses = 0;

        foreach ($pas as $achat) {
            $base = self::ajouter($base, $achat['base']);
            $cout = self::ajouter($cout, $achat['cout']);

            if ($achat['ecart'] > 0) {
                $hausses = self::ajouter($hausses, $achat['ecart']);
            } elseif ($achat['ecart'] < 0) {
                $baisses = self::soustraire($baisses, $achat['ecart']);
            }
        }

        return [
            'k' => $k,
            'm' => $libelle,
            'base' => $base,
            'cout' => $cout,
            'ecart' => self::soustraire($cout, $base),
            'hausses' => $hausses,
            'baisses' => $baisses,
            'n' => count($pas),
            'sans' => count($premiers),
            'pas' => $pas,
            'premiers' => $premiers,
        ];
    }

    /**
     * Les achats d'un produit rangés par mois, chaque liste dans l'ordre
     * donné, du premier mois au dernier.
     *
     * @param  list<Achat>  $achats
     * @return array<int, non-empty-list<Achat>>
     */
    private static function parMois(array $achats): array
    {
        $parMois = [];

        foreach ($achats as $achat) {
            $parMois[$achat['k']][] = $achat;
        }

        ksort($parMois);

        return $parMois;
    }

    /**
     * Contrôle chaque achat d'un produit : quatre entiers de valeur absolue
     * au plus 9 007 199 254 740 991, et un mois de 0 à 11.
     *
     * @param  array<int, array<string, mixed>>  $achats
     * @return list<Achat>
     */
    private static function lireLesAchats(array $achats): array
    {
        $lus = [];

        foreach ($achats as $achat) {
            $k = self::entierDe($achat, 'k');

            if ($k < 0 || $k >= self::NOMBRE_DE_MOIS) {
                throw new InvalidArgumentException('Le mois d\'un achat est un numéro de 0 à 11 : '.var_export($achat, true));
            }

            $lus[] = ['k' => $k, 'j' => self::entierDe($achat, 'j'), 'q' => self::entierDe($achat, 'q'), 'c' => self::entierDe($achat, 'c')];
        }

        return $lus;
    }

    /**
     * Une valeur d'un achat : un entier de valeur absolue au plus
     * 9 007 199 254 740 991.
     *
     * @param  array<string, mixed>  $achat
     */
    private static function entierDe(array $achat, string $cle): int
    {
        $valeur = $achat[$cle] ?? null;

        if (! is_int($valeur) || abs($valeur) > EcritureDesEcarts::ENTIER_SUR_MAXIMAL) {
            throw new InvalidArgumentException('Un achat est fait de quatre entiers (mois, jour, quantité, prix en centimes) : '.var_export($achat, true));
        }

        return $valeur;
    }

    private static function ajouter(int $a, int $b): int
    {
        return self::entier($a + $b);
    }

    private static function soustraire(int $a, int $b): int
    {
        return self::entier($a - $b);
    }

    private static function multiplier(int $a, int $b): int
    {
        return self::entier($a * $b);
    }

    /**
     * Un résultat qui dépasse les entiers de PHP devient un nombre à
     * virgule : il est refusé, jamais gardé.
     */
    private static function entier(int|float $valeur): int
    {
        if (! is_int($valeur)) {
            throw new OverflowException('Un montant dépasse les entiers : le calcul est refusé.');
        }

        return $valeur;
    }
}
