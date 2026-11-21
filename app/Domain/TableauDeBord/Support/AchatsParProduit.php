<?php

namespace App\Domain\TableauDeBord\Support;

use App\Domain\TableauDeBord\Services\AchatsDuTableauDeBord;
use InvalidArgumentException;

/**
 * Les achats de la carte « Écart des prix d'achat » du tableau de bord
 * (décision de l'éditeur du 21/11/2026, D76, B3 à B5) : les lignes de
 * factures d'achat validées lues par AchatsDuTableauDeBord::lignes(),
 * rangées pour EcartDesPrixDAchat::calculer(), sans base de données ni
 * Eloquent.
 *
 * Seules comptent les lignes dont le mois est l'un des douze mois de la
 * période : un achat d'avant la période n'est jamais le prix de départ d'un
 * produit. Les achats se groupent par (magasin, produit), jamais par le seul
 * produit : un produit appartient à un seul magasin, et les prix de deux
 * magasins ne se mélangent jamais. Ceux d'un groupe sont ordonnés par jour,
 * puis par rang de validation de leur facture, unique dans le magasin : cet
 * ordre est total, puisqu'une facture d'achat n'a jamais deux lignes du même
 * produit, et le « premier achat » du produit est le premier de la liste.
 * L'ordre des produits est celui de leur création, l'identifiant croissant
 * du produit.
 *
 * Quand la carte compte plus d'un magasin, le nom d'un produit s'écrit « nom
 * · nom du magasin » ; avec un seul, jamais un nom de magasin.
 *
 * @phpstan-import-type LigneDAchatDuTableau from AchatsDuTableauDeBord
 */
final class AchatsParProduit
{
    /**
     * @param  list<LigneDAchatDuTableau>  $lignes
     * @param  list<string>  $mois  les douze mois « AAAA-MM » de la période
     * @param  bool  $plusieursMagasins  la carte compte plus d'un magasin : le nom d'un produit dit le sien
     * @param  array<int, string>  $nomsDesMagasins  le nom de chaque magasin compté, par identifiant, pour ce cas
     * @return list<array{nom: string, achats: list<array{k: int, j: int, q: int, c: int}>}>
     *
     * @throws InvalidArgumentException le nom d'un magasin manque alors qu'il faut le dire
     */
    public static function depuis(array $lignes, array $mois, bool $plusieursMagasins, array $nomsDesMagasins = []): array
    {
        $rangDuMois = array_flip($mois);
        /** @var array<string, array{produit: int, nom: string, magasin: int, achats: list<array{jour: string, rang: int, k: int, j: int, q: int, c: int}>}> $groupes */
        $groupes = [];

        foreach ($lignes as $ligne) {
            $moisDeLaLigne = substr($ligne['jour'], 0, 7);

            if (! isset($rangDuMois[$moisDeLaLigne])) {
                continue;
            }

            $cle = $ligne['magasin'].':'.$ligne['produit'];
            $groupes[$cle] ??= ['produit' => $ligne['produit'], 'nom' => $ligne['nom'], 'magasin' => $ligne['magasin'], 'achats' => []];
            $groupes[$cle]['achats'][] = [
                'jour' => $ligne['jour'],
                'rang' => $ligne['rang'],
                'k' => $rangDuMois[$moisDeLaLigne],
                'j' => (int) substr($ligne['jour'], 8, 2),
                'q' => $ligne['quantite'],
                'c' => $ligne['prix'],
            ];
        }

        uasort($groupes, fn (array $un, array $autre): int => [$un['produit'], $un['magasin']] <=> [$autre['produit'], $autre['magasin']]);
        $produits = [];

        foreach ($groupes as $groupe) {
            $achats = $groupe['achats'];
            usort($achats, fn (array $un, array $autre): int => [$un['jour'], $un['rang']] <=> [$autre['jour'], $autre['rang']]);

            $produits[] = [
                'nom' => $groupe['nom'].($plusieursMagasins ? ' · '.self::nomDuMagasin($groupe['magasin'], $nomsDesMagasins) : ''),
                'achats' => array_map(fn (array $achat): array => ['k' => $achat['k'], 'j' => $achat['j'], 'q' => $achat['q'], 'c' => $achat['c']], $achats),
            ];
        }

        return $produits;
    }

    /**
     * @param  array<int, string>  $nomsDesMagasins
     */
    private static function nomDuMagasin(int $magasin, array $nomsDesMagasins): string
    {
        return $nomsDesMagasins[$magasin] ?? throw new InvalidArgumentException('Le nom du magasin d\'un produit manque.');
    }
}
