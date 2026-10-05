<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Un prix unitaire HT saisi via "PU TTC" (voir DevisResource::htDepuisTtc())
 * était arrondi à 2 décimales dès le stockage : pour un prix TTC rond qui
 * ne se divise pas exactement par 1 + taux de TVA (ex: 40 € / 1,20 =
 * 33,3333...), cet arrondi prématuré faisait dériver le total TTC d'une
 * ligne dès que la quantité dépassait 1 (ex: 2 x 33,33 € HT -> 79,99 € TTC
 * au lieu des 80,00 € attendus). Porter la précision à 4 décimales laisse
 * assez de marge pour que la quantité reconstitue exactement le prix TTC
 * visé -- les montants finaux (montant_ht/tva/ttc), eux, restent à 2
 * décimales : ce sont les seuls à apparaître sur le document légal.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->tablesEtColonnes() as $table => $colonnes) {
            Schema::table($table, function (Blueprint $blueprint) use ($colonnes) {
                foreach ($colonnes as $colonne) {
                    $blueprint->decimal($colonne, 14, 4)->change();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tablesEtColonnes() as $table => $colonnes) {
            Schema::table($table, function (Blueprint $blueprint) use ($colonnes) {
                foreach ($colonnes as $colonne) {
                    $blueprint->decimal($colonne, 12, 2)->change();
                }
            });
        }
    }

    /**
     * @return array<string, list<string>>
     */
    private function tablesEtColonnes(): array
    {
        return [
            'produits' => ['prix_achat_ht', 'prix_vente_ht'],
            'lignes_facture' => ['prix_unitaire_ht'],
            'lignes_devis' => ['prix_unitaire_ht'],
            'lignes_achat' => ['prix_unitaire_ht'],
            'lignes_avoir' => ['prix_unitaire_ht'],
            'lignes_avoir_fournisseur' => ['prix_unitaire_ht'],
        ];
    }
};
