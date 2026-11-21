<?php

namespace Tests\Unit;

use App\Domain\TableauDeBord\Support\AchatsParProduit;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Le rangement des lignes d'achat pour la carte « Écart des prix d'achat »
 * (décision de l'éditeur du 21/11/2026, D76, B3 à B5), sans base de données :
 * le filtre des douze mois de la période, le groupement par (magasin,
 * produit), l'ordre des achats (jour, puis rang de validation), l'ordre des
 * produits (leur identifiant), le nom du produit qui dit son magasin quand
 * la carte en compte plusieurs.
 */
class AchatsParProduitTest extends TestCase
{
    /** @var list<string> */
    private const MOIS = ['2025-12', '2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'];

    /**
     * @return array{magasin: int, produit: int, nom: string, jour: string, rang: int, quantite: int, prix: int}
     */
    private static function ligne(int $magasin, int $produit, string $nom, string $jour, int $rang, int $quantite = 1, int $prix = 500): array
    {
        return ['magasin' => $magasin, 'produit' => $produit, 'nom' => $nom, 'jour' => $jour, 'rang' => $rang, 'quantite' => $quantite, 'prix' => $prix];
    }

    /**
     * Seules comptent les lignes des douze mois de la période : un achat
     * d'avant (novembre 2025) ou d'après (décembre 2026) n'est jamais un
     * achat de la carte, et jamais un prix de départ ; le mois et le jour
     * deviennent `k` (0 pour décembre 2025) et `j`.
     */
    public function test_seuls_les_achats_des_douze_mois_de_la_periode_comptent(): void
    {
        $produits = AchatsParProduit::depuis([
            self::ligne(1, 7, 'Lampe', '2025-11-30', 1, 4, 900),
            self::ligne(1, 7, 'Lampe', '2025-12-01', 2, 2, 500),
            self::ligne(1, 7, 'Lampe', '2026-11-30', 3, 3, 600),
            self::ligne(1, 7, 'Lampe', '2026-12-01', 4, 5, 700),
        ], self::MOIS, false);

        $this->assertSame([['nom' => 'Lampe', 'achats' => [['k' => 0, 'j' => 1, 'q' => 2, 'c' => 500], ['k' => 11, 'j' => 30, 'q' => 3, 'c' => 600]]]], $produits);
    }

    /**
     * Les achats d'un produit sont ordonnés par jour, puis, à égalité, par
     * rang de validation de leur facture, quel que soit l'ordre des lignes
     * lues.
     */
    public function test_les_achats_d_un_produit_se_rangent_par_jour_puis_par_rang_de_validation(): void
    {
        $produits = AchatsParProduit::depuis([
            self::ligne(1, 7, 'Lampe', '2026-03-15', 9, 1, 800),
            self::ligne(1, 7, 'Lampe', '2026-03-04', 5, 1, 500),
            self::ligne(1, 7, 'Lampe', '2026-03-15', 3, 1, 600),
            self::ligne(1, 7, 'Lampe', '2026-02-20', 8, 1, 400),
        ], self::MOIS, false);

        $this->assertSame([400, 500, 600, 800], array_column($produits[0]['achats'], 'c'));
        $this->assertSame([2, 3, 3, 3], array_column($produits[0]['achats'], 'k'));
    }

    /**
     * Les produits de deux magasins ne se mélangent jamais, même de même nom
     * (et même, par erreur de lecture, de même identifiant) : le groupe est
     * le couple (magasin, produit). Les produits se rangent par identifiant
     * croissant, c'est-à-dire dans l'ordre de leur création.
     */
    public function test_les_produits_de_deux_magasins_ne_se_melangent_jamais_et_se_rangent_par_identifiant(): void
    {
        $produits = AchatsParProduit::depuis([
            self::ligne(2, 30, 'Lampe', '2026-03-04', 5, 1, 2000),
            self::ligne(1, 20, 'Lampe', '2026-03-04', 6, 1, 500),
            self::ligne(2, 30, 'Lampe', '2026-03-10', 7, 1, 2200),
            self::ligne(1, 20, 'Lampe', '2026-03-12', 8, 1, 600),
            self::ligne(1, 10, 'Bougie', '2026-03-05', 9, 1, 300),
        ], self::MOIS, false);

        $this->assertSame(['Bougie', 'Lampe', 'Lampe'], array_column($produits, 'nom'));
        $this->assertSame([[300], [500, 600], [2000, 2200]], array_map(fn (array $produit): array => array_column($produit['achats'], 'c'), $produits));
    }

    /**
     * Un même numéro de produit dans deux magasins reste deux groupes.
     */
    public function test_un_meme_identifiant_dans_deux_magasins_reste_deux_groupes(): void
    {
        $produits = AchatsParProduit::depuis([
            self::ligne(1, 5, 'Lampe', '2026-03-04', 1, 1, 500),
            self::ligne(2, 5, 'Lampe', '2026-03-04', 1, 1, 900),
        ], self::MOIS, true, [1 => 'Lyon Centre', 2 => 'Villeurbanne']);

        $this->assertCount(2, $produits);
        $this->assertSame(['Lampe · Lyon Centre', 'Lampe · Villeurbanne'], array_column($produits, 'nom'));
    }

    /**
     * Avec plus d'un magasin compté, le nom d'un produit dit le sien (« nom ·
     * nom du magasin ») ; avec un seul, jamais.
     */
    public function test_le_nom_dit_le_magasin_seulement_quand_plusieurs_sont_comptes(): void
    {
        $lignes = [self::ligne(1, 5, 'Lampe', '2026-03-04', 1)];

        $this->assertSame('Lampe · Lyon Centre', AchatsParProduit::depuis($lignes, self::MOIS, true, [1 => 'Lyon Centre'])[0]['nom']);
        $this->assertSame('Lampe', AchatsParProduit::depuis($lignes, self::MOIS, false, [1 => 'Lyon Centre'])[0]['nom']);
    }

    public function test_le_nom_d_un_magasin_qui_manque_est_refuse(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AchatsParProduit::depuis([self::ligne(1, 5, 'Lampe', '2026-03-04', 1)], self::MOIS, true, [2 => 'Villeurbanne']);
    }

    public function test_aucune_ligne_ne_donne_aucun_produit(): void
    {
        $this->assertSame([], AchatsParProduit::depuis([], self::MOIS, false));
        $this->assertSame([], AchatsParProduit::depuis([self::ligne(1, 5, 'Lampe', '2024-03-04', 1)], self::MOIS, false));
    }
}
