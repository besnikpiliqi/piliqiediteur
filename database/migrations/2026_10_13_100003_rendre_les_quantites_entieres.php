<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Une quantité est toujours un nombre entier d'articles (voir
 * QuantiteEntiere) : les colonnes decimal(12,3) prévues au départ pour des
 * quantités au poids faisaient afficher "2.000" partout, et laissaient
 * passer 1,5 article.
 *
 * Aucune quantité n'est jamais arrondie ici : une ligne de facture émise
 * est un document légal, la modifier en silence fausserait son montant.
 * S'il en existe une à virgule, la migration s'arrête AVANT toute
 * modification et dit laquelle.
 *
 * Jamais dans une transaction : sous SQLite, changer le type d'une colonne
 * reconstruit la table, et Laravel ne coupe les clés étrangères pendant
 * cette reconstruction qu'en dehors d'une transaction -- dedans, supprimer
 * l'ancienne table produits viderait mouvements_stock (cascade) et les
 * produit_id des lignes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->verifierQueToutesLesQuantitesSontEntieres();

        foreach ($this->colonnes() as $table => $colonnes) {
            Schema::table($table, function (Blueprint $blueprint) use ($colonnes) {
                foreach ($colonnes as $colonne => $parDefaut) {
                    $definition = $blueprint->integer($colonne);

                    if ($parDefaut !== null) {
                        $definition->default($parDefaut);
                    }

                    $definition->change();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->colonnes() as $table => $colonnes) {
            Schema::table($table, function (Blueprint $blueprint) use ($colonnes) {
                foreach ($colonnes as $colonne => $parDefaut) {
                    $definition = $blueprint->decimal($colonne, 12, 3);

                    if ($parDefaut !== null) {
                        $definition->default($parDefaut);
                    }

                    $definition->change();
                }
            });
        }
    }

    private function verifierQueToutesLesQuantitesSontEntieres(): void
    {
        $quantitesAVirgule = [];

        foreach ($this->colonnes() as $table => $colonnes) {
            foreach (array_keys($colonnes) as $colonne) {
                $identifiants = DB::table($table)
                    ->whereRaw("{$colonne} <> ROUND({$colonne})")
                    ->orderBy('id')
                    ->pluck('id');

                if ($identifiants->isNotEmpty()) {
                    $quantitesAVirgule[] = "{$table}.{$colonne} (id {$identifiants->implode(', ')})";
                }
            }
        }

        if ($quantitesAVirgule !== []) {
            throw new RuntimeException(
                'Une quantité doit être un nombre entier d\'articles, mais ces lignes ont une quantité à virgule : '
                .implode(' ; ', $quantitesAVirgule)
                .'. Rien n\'a été modifié : corrigez ces quantités puis relancez la migration.'
            );
        }
    }

    /**
     * Colonne => valeur par défaut, à répéter à chaque changement de type
     * (sans quoi elle serait perdue).
     *
     * @return array<string, array<string, int|null>>
     */
    private function colonnes(): array
    {
        return [
            'produits' => ['stock_quantite' => 0, 'seuil_alerte' => 0],
            'mouvements_stock' => ['quantite' => null],
            'lignes_devis' => ['quantite' => null],
            'lignes_facture' => ['quantite' => null],
            'lignes_avoir' => ['quantite' => null],
            'lignes_achat' => ['quantite' => null],
            'lignes_avoir_fournisseur' => ['quantite' => null],
        ];
    }
};
