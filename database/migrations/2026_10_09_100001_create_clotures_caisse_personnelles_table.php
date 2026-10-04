<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clotures_caisse_personnelles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            // Toujours un vendeur réel, jamais le système : contrairement
            // à ClotureCaisse, il n'y a pas de déclenchement automatique
            // ici (voir le docblock de FermerSaCaisseAction).
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            // Curseur d'exclusion pour la fermeture suivante du même
            // vendeur : un ID de facture est strictement croissant et
            // jamais à égalité, contrairement à un horodatage qui peut
            // tomber sur la même seconde que la fermeture précédente et
            // faire rater ou dupliquer une vente à la frontière.
            $table->foreignId('dernier_facture_id')->nullable()->constrained('factures')->nullOnDelete();
            // Bornes purement informatives (affichage), pas utilisées pour
            // déterminer quelles factures sont incluses -- voir
            // dernier_facture_id ci-dessus.
            $table->timestamp('periode_debut');
            $table->timestamp('cloturee_a');
            $table->unsignedInteger('nombre_ventes')->default(0);
            $table->decimal('total_ht', 12, 2)->default(0);
            $table->decimal('total_tva', 12, 2)->default(0);
            $table->decimal('total_ttc', 12, 2)->default(0);
            $table->timestamps();
            // Pas de numéro, pas de statut : simple relevé personnel de
            // caisse, sans existence fiscale -- ne remplace jamais, et ne
            // bloque jamais, la clôture officielle de la journée du
            // magasin (voir ClotureCaisse).

            $table->index(['magasin_id', 'cree_par', 'cloturee_a']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clotures_caisse_personnelles');
    }
};
