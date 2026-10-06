<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Une fermeture de caisse personnelle note désormais explicitement les
 * ventes qu'elle a comptées, au lieu d'un curseur "dernier ID de facture
 * inclus" : l'ID d'une facture est attribué à la création du brouillon,
 * pas à l'émission, si bien qu'un brouillon créé avant une vente puis émis
 * après la fermeture était sauté pour toujours.
 *
 * Reprise des fermetures existantes, vendeur par vendeur et magasin par
 * magasin : chacune a compté les factures de son intervalle de curseur
 * déjà émises au moment de la fermeture. Une facture de l'intervalle émise
 * APRÈS la fermeture n'a jamais été comptée -- elle reste non notée, et la
 * prochaine fermeture la rattrapera.
 *
 * Jamais dans une transaction : sous SQLite, supprimer la colonne du
 * curseur reconstruit la table (voir rendre_les_quantites_entieres).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clotures_caisse_personnelles_factures', function (Blueprint $table) {
            // Clé primaire : une vente n'est comptée que dans une seule
            // fermeture. Noms de contraintes explicites : ceux que Laravel
            // dériverait dépassent les 64 caractères qu'accepte MySQL.
            $table->foreignId('facture_id')->primary()->constrained('factures', 'id', 'ccp_factures_facture_foreign')->cascadeOnDelete();
            $table->foreignId('cloture_caisse_personnelle_id')->constrained('clotures_caisse_personnelles', 'id', 'ccp_factures_cloture_foreign')->cascadeOnDelete();
        });

        $this->reprendreLesFermeturesExistantes();

        Schema::table('clotures_caisse_personnelles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('dernier_facture_id');
        });
    }

    public function down(): void
    {
        Schema::table('clotures_caisse_personnelles', function (Blueprint $table) {
            $table->foreignId('dernier_facture_id')->nullable()->after('cree_par')->constrained('factures')->nullOnDelete();
        });

        $this->reconstruireLesCurseurs();

        Schema::dropIfExists('clotures_caisse_personnelles_factures');
    }

    private function reprendreLesFermeturesExistantes(): void
    {
        $fermeturesParVendeur = DB::table('clotures_caisse_personnelles')
            ->whereNotNull('cree_par')
            ->orderBy('id')
            ->get(['id', 'magasin_id', 'cree_par', 'dernier_facture_id', 'cloturee_a'])
            ->groupBy(fn (object $fermeture): string => "{$fermeture->magasin_id}-{$fermeture->cree_par}");

        foreach ($fermeturesParVendeur as $fermetures) {
            $curseurPrecedent = 0;

            foreach ($fermetures as $fermeture) {
                $curseur = (int) ($fermeture->dernier_facture_id ?? 0);

                if ($curseur > $curseurPrecedent) {
                    $facturesComptees = DB::table('factures')
                        ->where('magasin_id', $fermeture->magasin_id)
                        ->where('cree_par', $fermeture->cree_par)
                        ->where('id', '>', $curseurPrecedent)
                        ->where('id', '<=', $curseur)
                        ->whereNotIn('statut', ['brouillon', 'annulee'])
                        ->where('date_emission', '<=', $fermeture->cloturee_a)
                        ->pluck('id');

                    DB::table('clotures_caisse_personnelles_factures')->insertOrIgnore($facturesComptees
                        ->map(fn (int $factureId): array => ['cloture_caisse_personnelle_id' => $fermeture->id, 'facture_id' => $factureId])
                        ->all());

                    $curseurPrecedent = $curseur;
                }
            }
        }
    }

    /**
     * Curseur d'avant : la plus grande facture notée par la fermeture, ou
     * celui de la fermeture précédente du même vendeur si elle n'en a noté
     * aucune.
     */
    private function reconstruireLesCurseurs(): void
    {
        $fermeturesParVendeur = DB::table('clotures_caisse_personnelles')
            ->orderBy('id')
            ->get(['id', 'magasin_id', 'cree_par'])
            ->groupBy(fn (object $fermeture): string => "{$fermeture->magasin_id}-{$fermeture->cree_par}");

        foreach ($fermeturesParVendeur as $fermetures) {
            $curseur = null;

            foreach ($fermetures as $fermeture) {
                $derniereFactureNotee = DB::table('clotures_caisse_personnelles_factures')
                    ->where('cloture_caisse_personnelle_id', $fermeture->id)
                    ->max('facture_id');

                $curseur = $derniereFactureNotee !== null ? max((int) $derniereFactureNotee, (int) $curseur) : $curseur;

                DB::table('clotures_caisse_personnelles')->where('id', $fermeture->id)->update(['dernier_facture_id' => $curseur]);
            }
        }
    }
};
