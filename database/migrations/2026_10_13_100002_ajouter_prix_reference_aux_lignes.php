<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chaque ligne retient le prix réellement saisi (HT ou TTC) qui fait foi
 * pour son calcul (voir PrixReference) : 40 € TTC x 2 = 80,00 € TTC, et
 * 8,33 € HT x 3 = 24,99 € HT, quel que soit le client ou le document --
 * ventes comme achats.
 *
 * Lignes existantes : les ventes gardent le TTC comme référence (c'est ce
 * qu'appliquait déjà ancrer_prix_de_vente_sur_le_ttc), les achats le HT
 * (seul prix saisissable jusqu'ici). Les lignes d'achat reçoivent leur PU
 * TTC, calculé en entiers en PHP pour la même raison que dans cette
 * migration précédente.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->referenceParDefaut() as $table => $reference) {
            Schema::table($table, function (Blueprint $blueprint) use ($table, $reference) {
                if (in_array($table, $this->tablesDAchat(), true)) {
                    $blueprint->decimal('prix_unitaire_ttc', 12, 2)->default(0)->after('prix_unitaire_ht');
                }

                $blueprint->string('prix_reference', 3)->default($reference)->after('prix_unitaire_ttc');
            });
        }

        foreach ($this->tablesDAchat() as $table) {
            DB::table($table)->orderBy('id')->chunkById(500, function ($lignes) use ($table) {
                foreach ($lignes as $ligne) {
                    DB::table($table)->where('id', $ligne->id)->update([
                        'prix_unitaire_ttc' => $this->ttcDepuisHt($ligne->prix_unitaire_ht, $ligne->taux_tva),
                    ]);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->referenceParDefaut()) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropColumn('prix_reference');

                if (in_array($table, $this->tablesDAchat(), true)) {
                    $blueprint->dropColumn('prix_unitaire_ttc');
                }
            });
        }
    }

    private function ttcDepuisHt(mixed $ht, mixed $tauxTva): string
    {
        $htCentimes = (int) round(round((float) $ht * 100, 6));
        $tauxCentiemes = (int) round(round((float) $tauxTva * 100, 6));
        $numerateur = $htCentimes * (10000 + $tauxCentiemes);
        $centimes = intdiv($numerateur, 10000);

        if (2 * abs($numerateur % 10000) >= 10000) {
            $centimes += $numerateur < 0 ? -1 : 1;
        }

        return number_format($centimes / 100, 2, '.', '');
    }

    /**
     * @return array<string, string>
     */
    private function referenceParDefaut(): array
    {
        return [
            'lignes_devis' => 'ttc',
            'lignes_facture' => 'ttc',
            'lignes_avoir' => 'ttc',
            'lignes_achat' => 'ht',
            'lignes_avoir_fournisseur' => 'ht',
        ];
    }

    /**
     * @return list<string>
     */
    private function tablesDAchat(): array
    {
        return ['lignes_achat', 'lignes_avoir_fournisseur'];
    }
};
