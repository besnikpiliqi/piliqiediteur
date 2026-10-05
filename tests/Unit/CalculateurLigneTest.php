<?php

namespace Tests\Unit;

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
     * Cas réel ayant révélé le bug : deux lignes à 8,33 € HT (TVA 20 %)
     * affichent chacune 10,00 € TTC, donc un total de 20,00 €. Avant la
     * correction, un calcul en flottant non arrondi par ligne (8.33 * 1.2 =
     * 9.996 pour chaque ligne) sommait à 19,992 €, affiché "19,99 €" --
     * un centime de moins que la somme visible des deux lignes.
     */
    public function test_deux_lignes_a_8_33_ht_totalisent_exactement_20_euros(): void
    {
        $ligne = $this->calculateur()->calculer(prixUnitaireHt: 8.33, quantite: 1, tauxTva: 20);

        $this->assertSame(10.0, $ligne['montant_ttc']);

        $total = $this->calculateur()->totaliser([$ligne, $ligne]);

        $this->assertSame(20.0, $total['total_ttc']);
    }

    public function test_une_remise_en_pourcentage_est_appliquee_avant_la_tva(): void
    {
        $ligne = $this->calculateur()->calculer(
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
        $ligne = $this->calculateur()->calculer(prixUnitaireHt: 4.99, quantite: 1.5, tauxTva: 20);

        // 4.99 * 1.5 = 7.485 -> arrondi en centimes entiers à 7.49 (jamais
        // de round() sur un flottant intermédiaire non garanti exact).
        $this->assertSame(7.49, $ligne['montant_ht']);
    }

    /**
     * Cas réel signalé par un magasin : un produit visé à 40 € TTC (TVA
     * 20 %) donne un prix HT de 40 / 1,20 = 33,3333... €. Arrondi à 2
     * décimales dès le stockage (33,33 €), ce prix unitaire multiplié par
     * une quantité de 2 donnait 79,99 € TTC au lieu des 80,00 € attendus --
     * un centime manquant, visible dès que la quantité dépasse 1. Stocker
     * le prix unitaire avec 4 décimales (voir le docblock de la classe)
     * corrige ce cas précis sans rien changer pour un prix HT saisi
     * directement (déjà couvert par les autres tests de cette classe).
     */
    public function test_un_prix_derive_dun_ttc_rond_reste_exact_a_quantite_superieure_a_1(): void
    {
        $prixUnitaireHt = round(40 / 1.2, 4); // 33.3333

        $ligne = $this->calculateur()->calculer(prixUnitaireHt: $prixUnitaireHt, quantite: 2, tauxTva: 20);

        $this->assertSame(80.0, $ligne['montant_ttc']);
    }

    public function test_totaliser_ne_derive_jamais_meme_sur_un_grand_nombre_de_lignes(): void
    {
        $ligne = $this->calculateur()->calculer(prixUnitaireHt: 0.1, quantite: 1, tauxTva: 0);

        $total = $this->calculateur()->totaliser(array_fill(0, 1000, $ligne));

        $this->assertSame(100.0, $total['total_ttc']);
    }
}
