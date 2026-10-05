<?php

namespace Tests\Unit;

use App\Domain\Facturation\Enums\PrixReference;
use App\Domain\Facturation\Services\CalculateurLigne;
use Tests\TestCase;

/**
 * Tout le calcul se fait en centimes entiers (voir le commentaire de classe
 * de CalculateurLigne) : un flottant comme 8.33 n'a pas de représentation
 * binaire exacte, donc une somme de lignes arrondies en flottant peut
 * dériver d'un centime par rapport à la somme réellement affichée ligne par
 * ligne -- inacceptable une fois transmis à l'administration (API PDP).
 */
class CalculateurLigneTest extends TestCase
{
    private function calculateur(): CalculateurLigne
    {
        return new CalculateurLigne;
    }

    /**
     * Le prix réellement saisi fait foi, quel que soit le client : un PU TTC
     * saisi donne un TTC exact, un PU HT saisi donne un HT exact (et la TVA
     * exacte qu'un professionnel récupère).
     */
    public function test_calculer_part_toujours_du_prix_saisi(): void
    {
        $depuisTtc = $this->calculateur()->calculer(PrixReference::Ttc, prixUnitaireHt: 33.33, prixUnitaireTtc: 40, quantite: 2, tauxTva: 20);
        $depuisHt = $this->calculateur()->calculer(PrixReference::Ht, prixUnitaireHt: 8.33, prixUnitaireTtc: 10, quantite: 3, tauxTva: 20);

        $this->assertSame(['montant_ht' => 66.67, 'montant_tva' => 13.33, 'montant_ttc' => 80.0], $depuisTtc);
        $this->assertSame(['montant_ht' => 24.99, 'montant_tva' => 5.0, 'montant_ttc' => 29.99], $depuisHt);
    }

    public function test_prix_unitaires_garde_le_prix_saisi_et_convertit_lautre(): void
    {
        $this->assertSame(['prix_unitaire_ht' => 33.33, 'prix_unitaire_ttc' => 40.0], $this->calculateur()->prixUnitaires(PrixReference::Ttc, 40, 20));
        $this->assertSame(['prix_unitaire_ht' => 8.33, 'prix_unitaire_ttc' => 10.0], $this->calculateur()->prixUnitaires(PrixReference::Ht, 8.33, 20));
    }

    /**
     * Deux retours d'un article sur une ligne de 2 à 40 € TTC : recalculés
     * chacun, ils créditeraient 66,66 € HT et 13,34 € de TVA au lieu des
     * 66,67 € / 13,33 € facturés. Le dernier retour prend donc le solde.
     */
    public function test_le_solde_dune_ligne_retombe_exactement_sur_la_ligne_facturee(): void
    {
        $ligne = $this->calculateur()->calculerDepuisTtc(prixUnitaireTtc: 40, quantite: 2, tauxTva: 20);
        $premierRetour = $this->calculateur()->calculerDepuisTtc(prixUnitaireTtc: 40, quantite: 1, tauxTva: 20);

        $solde = $this->calculateur()->solder($ligne, [$premierRetour]);

        $this->assertSame(['montant_ht' => 33.34, 'montant_tva' => 6.66, 'montant_ttc' => 40.0], $solde);
        $this->assertSame(
            ['total_ht' => 66.67, 'total_tva' => 13.33, 'total_ttc' => 80.0],
            $this->calculateur()->totaliser([$premierRetour, $solde]),
        );
    }

    /**
     * Cas réel signalé par un magasin : un article à 40 € TTC vendu par 2
     * affichait 79,99 € (33,33 € HT x 2 x 1,20). Le TTC faisant référence,
     * le total est 40 x 2 = 80,00 €, le HT n'étant déduit qu'une fois, du
     * total de la ligne.
     */
    public function test_40_euros_ttc_vendus_par_2_totalisent_exactement_80_euros(): void
    {
        $ligne = $this->calculateur()->calculerDepuisTtc(prixUnitaireTtc: 40, quantite: 2, tauxTva: 20);

        $this->assertSame(80.0, $ligne['montant_ttc']);
        $this->assertSame(66.67, $ligne['montant_ht']);
        $this->assertSame(13.33, $ligne['montant_tva']);
    }

    /**
     * Un retour partiel (1 article sur 2) crédite exactement la moitié de
     * la ligne d'origine : deux retours successifs d'un article retombent
     * pile sur les 80,00 € vendus, jamais sur 79,99 € ou 80,01 €.
     */
    public function test_deux_retours_partiels_retombent_exactement_sur_la_ligne_vendue(): void
    {
        $retour = $this->calculateur()->calculerDepuisTtc(prixUnitaireTtc: 40, quantite: 1, tauxTva: 20);

        $this->assertSame(40.0, $retour['montant_ttc']);
        $this->assertSame(80.0, $this->calculateur()->totaliser([$retour, $retour])['total_ttc']);
    }

    public function test_remise_et_quantite_fractionnaire_en_ttc_restent_exactes_au_centime(): void
    {
        $ligne = $this->calculateur()->calculerDepuisTtc(
            prixUnitaireTtc: 9.99,
            quantite: 1.5,
            tauxTva: 20,
            remisePourcentage: 10,
        );

        // 9,99 x 1,5 = 14,985 -> 14,99 ; remise 10 % = 1,50 -> 13,49 TTC ;
        // HT = 13,49 / 1,20 = 11,2416... -> 11,24 ; TVA = 13,49 - 11,24.
        $this->assertSame(13.49, $ligne['montant_ttc']);
        $this->assertSame(11.24, $ligne['montant_ht']);
        $this->assertSame(2.25, $ligne['montant_tva']);
    }

    public function test_un_taux_reduit_decimal_reste_exact(): void
    {
        $ligne = $this->calculateur()->calculerDepuisTtc(prixUnitaireTtc: 10, quantite: 1, tauxTva: 5.5);

        // 10,00 / 1,055 = 9,4786... -> 9,48 HT.
        $this->assertSame(10.0, $ligne['montant_ttc']);
        $this->assertSame(9.48, $ligne['montant_ht']);
        $this->assertSame(0.52, $ligne['montant_tva']);
    }

    public function test_le_pu_ht_affiche_est_la_conversion_au_centime_du_pu_ttc(): void
    {
        $this->assertSame(33.33, $this->calculateur()->prixUnitaireHtDepuisTtc(40, 20));
        $this->assertSame(40.0, $this->calculateur()->prixUnitaireTtcDepuisHt(33.33, 20));
    }

    /**
     * 8.325 vaut réellement 8.32499999... en binaire : un arrondi naïf
     * donnerait 8,32 au lieu de 8,33.
     */
    public function test_arrondir_au_centime_ne_tombe_pas_dans_le_piege_du_flottant(): void
    {
        $this->assertSame(8.33, $this->calculateur()->arrondirAuCentime(8.325));
    }

    /**
     * Cas réel ayant révélé le bug : deux lignes à 8,33 € HT (TVA 20 %)
     * affichent chacune 10,00 € TTC, donc un total de 20,00 €. Avant la
     * correction, un calcul en flottant non arrondi par ligne (8.33 * 1.2 =
     * 9.996 pour chaque ligne) sommait à 19,992 €, affiché "19,99 €" --
     * un centime de moins que la somme visible des deux lignes.
     */
    public function test_deux_lignes_a_8_33_ht_totalisent_exactement_20_euros(): void
    {
        $ligne = $this->calculateur()->calculerDepuisHt(prixUnitaireHt: 8.33, quantite: 1, tauxTva: 20);

        $this->assertSame(10.0, $ligne['montant_ttc']);

        $total = $this->calculateur()->totaliser([$ligne, $ligne]);

        $this->assertSame(20.0, $total['total_ttc']);
    }

    public function test_une_remise_en_pourcentage_est_appliquee_avant_la_tva(): void
    {
        $ligne = $this->calculateur()->calculerDepuisHt(
            prixUnitaireHt: 100,
            quantite: 1,
            tauxTva: 20,
            remisePourcentage: 10,
        );

        $this->assertSame(90.0, $ligne['montant_ht']);
        $this->assertSame(18.0, $ligne['montant_tva']);
        $this->assertSame(108.0, $ligne['montant_ttc']);
    }

    public function test_une_quantite_fractionnaire_reste_exacte_au_centime(): void
    {
        $ligne = $this->calculateur()->calculerDepuisHt(prixUnitaireHt: 4.99, quantite: 1.5, tauxTva: 20);

        // 4.99 * 1.5 = 7.485 -> arrondi en centimes entiers à 7.49 (jamais
        // de round() sur un flottant intermédiaire non garanti exact).
        $this->assertSame(7.49, $ligne['montant_ht']);
        $this->assertSame(1.5, $ligne['montant_tva']);
        $this->assertSame(8.99, $ligne['montant_ttc']);
    }

    public function test_totaliser_ne_derive_jamais_meme_sur_un_grand_nombre_de_lignes(): void
    {
        $ligne = $this->calculateur()->calculerDepuisHt(prixUnitaireHt: 0.1, quantite: 1, tauxTva: 0);

        $total = $this->calculateur()->totaliser(array_fill(0, 1000, $ligne));

        $this->assertSame(100.0, $total['total_ttc']);
    }
}
