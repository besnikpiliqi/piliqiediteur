<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le prix de vente devient une donnée TTC enregistrée telle que saisie
 * (produit et lignes de devis/facture/avoir), au lieu d'être reconstruit à
 * partir d'un HT : 40 € TTC ne s'écrit pas exactement en HT (33,3333...),
 * et tout recalcul HT -> TTC finissait tôt ou tard à 79,99 € pour 2
 * articles (voir CalculateurLigne::calculerDepuisTtc()).
 *
 * Les TTC sont reconstitués depuis le HT existant AVANT de ramener ce HT à
 * 2 décimales : la précision à 4 décimales de la migration
 * augmenter_precision_prix_unitaire_ht n'a plus de raison d'être, le HT
 * n'étant plus qu'un montant d'affichage/mention légale côté vente, et un
 * prix saisi tel quel côté achat.
 *
 * La reprise se fait en PHP et en entiers, pas avec ROUND() en SQL : ce
 * dernier arrondit un flottant (1,055 peut y devenir 1,05 au lieu de 1,06)
 * et diffère d'un moteur de base à l'autre.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('produits', function (Blueprint $table) {
            $table->decimal('prix_vente_ttc', 12, 2)->default(0)->after('prix_vente_ht');
        });

        $this->reprendre('produits', fn (object $produit) => [
            'prix_vente_ttc' => $this->ttcDepuisHt($produit->prix_vente_ht, $produit->taux_tva),
        ]);

        foreach ($this->tablesDeVente() as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->decimal('prix_unitaire_ttc', 12, 2)->default(0)->after('prix_unitaire_ht');
            });

            $this->reprendre($table, fn (object $ligne) => [
                'prix_unitaire_ttc' => $this->ttcDepuisHt($ligne->prix_unitaire_ht, $ligne->taux_tva),
            ]);
        }

        foreach ($this->colonnesHt() as $table => $colonnes) {
            $this->reprendre($table, fn (object $ligne) => collect($colonnes)
                ->mapWithKeys(fn (string $colonne) => [$colonne => $this->auCentime($ligne->{$colonne})])
                ->all());

            Schema::table($table, function (Blueprint $blueprint) use ($colonnes) {
                foreach ($colonnes as $colonne) {
                    $blueprint->decimal($colonne, 12, 2)->change();
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->colonnesHt() as $table => $colonnes) {
            Schema::table($table, function (Blueprint $blueprint) use ($colonnes) {
                foreach ($colonnes as $colonne) {
                    $blueprint->decimal($colonne, 14, 4)->change();
                }
            });
        }

        foreach ($this->tablesDeVente() as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('prix_unitaire_ttc');
            });
        }

        Schema::table('produits', function (Blueprint $table) {
            $table->dropColumn('prix_vente_ttc');
        });
    }

    /**
     * @param  Closure(object): array<string, string>  $nouvellesValeurs
     */
    private function reprendre(string $table, Closure $nouvellesValeurs): void
    {
        DB::table($table)->orderBy('id')->chunkById(500, function ($lignes) use ($table, $nouvellesValeurs) {
            foreach ($lignes as $ligne) {
                DB::table($table)->where('id', $ligne->id)->update($nouvellesValeurs($ligne));
            }
        });
    }

    private function ttcDepuisHt(mixed $ht, mixed $tauxTva): string
    {
        $htDixMilliemes = (int) round(round((float) $ht * 10000, 6));
        $tauxCentiemes = (int) round(round((float) $tauxTva * 100, 6));

        return $this->centimesEnTexte($this->diviserArrondi($htDixMilliemes * (10000 + $tauxCentiemes), 1000000));
    }

    private function auCentime(mixed $montant): string
    {
        return $this->centimesEnTexte($this->diviserArrondi((int) round(round((float) $montant * 10000, 6)), 100));
    }

    private function diviserArrondi(int $numerateur, int $denominateur): int
    {
        $quotient = intdiv($numerateur, $denominateur);

        if (2 * abs($numerateur % $denominateur) >= $denominateur) {
            $quotient += $numerateur < 0 ? -1 : 1;
        }

        return $quotient;
    }

    private function centimesEnTexte(int $centimes): string
    {
        return number_format($centimes / 100, 2, '.', '');
    }

    /**
     * @return list<string>
     */
    private function tablesDeVente(): array
    {
        return ['lignes_devis', 'lignes_facture', 'lignes_avoir'];
    }

    /**
     * @return array<string, list<string>>
     */
    private function colonnesHt(): array
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
