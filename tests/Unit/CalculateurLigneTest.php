<?php

namespace Tests\Unit;

use App\Domain\Facturation\Enums\PrixReference;
use App\Domain\Facturation\Services\CalculateurLigne;
use Tests\TestCase;

/**
 * Calcul article par article (voir le commentaire de classe de
 * CalculateurLigne) : chaque article a son PU HT, sa TVA et son PU TTC au
 * centime, et chaque ligne multiplie séparément le HT et le TTC par la
 * quantité. Tout se fait en centimes entiers, jamais en flottant.
 */
class CalculateurLigneTest extends TestCase
{
    private function calculateur(): CalculateurLigne
    {
        return new CalculateurLigne;
    }

    /**
     * 40,00 € TTC = 33,33 € HT + 6,67 € de TVA ; 8,33 € HT = 8,33 € HT +
     * 1,67 € de TVA = 10,00 € TTC. Le prix saisi reste tel quel.
     */
    public function test_un_article_porte_son_ht_sa_tva_et_son_ttc_au_centime(): void
    {
        $this->assertSame(['prix_unitaire_ht' => 33.33, 'prix_unitaire_ttc' => 40.0], $this->calculateur()->prixUnitaires(PrixReference::Ttc, 40, 20));
        $this->assertSame(['prix_unitaire_ht' => 8.33, 'prix_unitaire_ttc' => 10.0], $this->calculateur()->prixUnitaires(PrixReference::Ht, 8.33, 20));
    }

    /**
     * Chaque montant de la ligne se vérifie à la main : PU x quantité, et
     * HT + TVA = TTC.
     */
    public function test_une_ligne_multiplie_separement_le_ht_et_le_ttc_par_la_quantite(): void
    {
        $this->assertSame(['montant_ht' => 66.66, 'montant_tva' => 13.34, 'montant_ttc' => 80.0], $this->calculateur()->calculer(33.33, 40, quantite: 2));
        $this->assertSame(['montant_ht' => 24.99, 'montant_tva' => 5.01, 'montant_ttc' => 30.0], $this->calculateur()->calculer(8.33, 10, quantite: 3));
    }

    /**
     * Facture signalée par le magasin : 2 x 40 € TTC (20 %), 10 € sans TVA
     * et 10 € TTC (20 %). Le total HT est la somme visible des PU HT x
     * quantité (84,99 €), et HT + TVA = TTC.
     */
    public function test_la_facture_du_magasin_totalise_84_99_ht_15_01_tva_100_ttc(): void
    {
        $calculateur = $this->calculateur();

        $totaux = $calculateur->totaliser([
            $calculateur->calculer(33.33, 40, 2),
            $calculateur->calculer(10, 10, 1),
            $calculateur->calculer(8.33, 10, 1),
        ]);

        $this->assertSame(['total_ht' => 84.99, 'total_tva' => 15.01, 'total_ttc' => 100.0], $totaux);
    }

    /**
     * Rendre 1 article puis le second d'une ligne de 2 crédite exactement
     * la ligne vendue, HT, TVA et TTC compris.
     */
    public function test_deux_retours_dun_article_retombent_exactement_sur_la_ligne_vendue(): void
    {
        $ligne = $this->calculateur()->calculer(33.33, 40, 2);
        $retour = $this->calculateur()->calculer(33.33, 40, 1);

        $this->assertSame(['montant_ht' => 33.33, 'montant_tva' => 6.67, 'montant_ttc' => 40.0], $retour);
        $this->assertSame(
            ['total_ht' => $ligne['montant_ht'], 'total_tva' => $ligne['montant_tva'], 'total_ttc' => $ligne['montant_ttc']],
            $this->calculateur()->totaliser([$retour, $retour]),
        );
    }

    /**
     * Quantité fractionnaire : 3 x 3,33 € HT = 9,99 €, mais 1,5 x 3,33 € =
     * 4,995 -> 5,00 € ; recalculer deux retours de 1,5 créditerait 10,00 €
     * HT. Le dernier retour prend donc le solde exact de la ligne.
     */
    public function test_le_solde_dune_ligne_retombe_exactement_sur_la_ligne_facturee(): void
    {
        $ligne = $this->calculateur()->calculer(3.33, 4, 3);
        $premierRetour = $this->calculateur()->calculer(3.33, 4, 1.5);

        $solde = $this->calculateur()->solder($ligne, [$premierRetour]);

        $this->assertSame(['montant_ht' => 9.99, 'montant_tva' => 2.01, 'montant_ttc' => 12.0], $ligne);
        $this->assertSame(['montant_ht' => 5.0, 'montant_tva' => 1.0, 'montant_ttc' => 6.0], $premierRetour);
        $this->assertSame(['montant_ht' => 4.99, 'montant_tva' => 1.01, 'montant_ttc' => 6.0], $solde);
    }

    public function test_remise_et_quantite_fractionnaire_restent_exactes_au_centime(): void
    {
        $ligne = $this->calculateur()->calculer(8.33, 9.99, quantite: 1.5, remisePourcentage: 10);

        // HT : 8,33 x 1,5 = 12,495 -> 12,50, remise 1,25 -> 11,25 ;
        // TTC : 9,99 x 1,5 = 14,985 -> 14,99, remise 1,50 -> 13,49.
        $this->assertSame(['montant_ht' => 11.25, 'montant_tva' => 2.24, 'montant_ttc' => 13.49], $ligne);
    }

    public function test_un_taux_reduit_decimal_reste_exact(): void
    {
        $prix = $this->calculateur()->prixUnitaires(PrixReference::Ttc, 10, 5.5);

        // 10,00 / 1,055 = 9,4786... -> 9,48 HT.
        $this->assertSame(['prix_unitaire_ht' => 9.48, 'prix_unitaire_ttc' => 10.0], $prix);
        $this->assertSame(['montant_ht' => 9.48, 'montant_tva' => 0.52, 'montant_ttc' => 10.0], $this->calculateur()->calculer(9.48, 10, 1));
    }

    /**
     * 8.325 vaut réellement 8.32499999... en binaire : un arrondi naïf
     * donnerait 8,32 au lieu de 8,33.
     */
    public function test_arrondir_au_centime_ne_tombe_pas_dans_le_piege_du_flottant(): void
    {
        $this->assertSame(8.33, $this->calculateur()->arrondirAuCentime(8.325));
    }

    public function test_deux_lignes_a_8_33_ht_totalisent_exactement_20_euros(): void
    {
        $ligne = $this->calculateur()->calculer(8.33, 10, 1);

        $this->assertSame(['montant_ht' => 8.33, 'montant_tva' => 1.67, 'montant_ttc' => 10.0], $ligne);
        $this->assertSame(20.0, $this->calculateur()->totaliser([$ligne, $ligne])['total_ttc']);
    }

    public function test_une_remise_en_pourcentage_sapplique_au_ht_comme_au_ttc(): void
    {
        $ligne = $this->calculateur()->calculer(100, 120, quantite: 1, remisePourcentage: 10);

        $this->assertSame(['montant_ht' => 90.0, 'montant_tva' => 18.0, 'montant_ttc' => 108.0], $ligne);
    }

    public function test_une_quantite_fractionnaire_reste_exacte_au_centime(): void
    {
        $prix = $this->calculateur()->prixUnitaires(PrixReference::Ht, 4.99, 20);
        $ligne = $this->calculateur()->calculer($prix['prix_unitaire_ht'], $prix['prix_unitaire_ttc'], 1.5);

        // 4,99 x 1,5 = 7,485 -> 7,49 HT ; 5,99 x 1,5 = 8,985 -> 8,99 TTC.
        $this->assertSame(['montant_ht' => 7.49, 'montant_tva' => 1.5, 'montant_ttc' => 8.99], $ligne);
    }

    public function test_totaliser_ne_derive_jamais_meme_sur_un_grand_nombre_de_lignes(): void
    {
        $ligne = $this->calculateur()->calculer(0.1, 0.1, 1);

        $total = $this->calculateur()->totaliser(array_fill(0, 1000, $ligne));

        $this->assertSame(100.0, $total['total_ttc']);
    }
}
