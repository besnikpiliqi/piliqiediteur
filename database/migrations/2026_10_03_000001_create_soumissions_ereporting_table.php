<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('soumissions_ereporting', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->string('numero', 40)->nullable();
            // Bornes de la période couverte (ex: une décade, un mois, un
            // bimestre selon le régime de TVA -- voir
            // CalculateurPeriodeEReporting), jamais un jour isolé : l'écart
            // avec date_cloture sur ClotureCaisse est volontaire.
            $table->date('periode_debut');
            $table->date('periode_fin');
            // Date limite légale calculée au moment de la génération :
            // conservée telle quelle même si la réglementation change
            // ensuite, pour l'audit.
            $table->date('date_limite_transmission');
            $table->unsignedInteger('nombre_clotures')->default(0);
            $table->decimal('total_ht', 12, 2)->default(0);
            $table->decimal('total_tva', 12, 2)->default(0);
            $table->decimal('total_ttc', 12, 2)->default(0);
            $table->timestamp('generee_le')->nullable();
            $table->timestamps();

            // Une seule soumission par période exacte et par magasin :
            // garantit l'idempotence de la commande planifiée (voir
            // GenererSoumissionEReportingAction). Nom explicite : celui
            // que Laravel dériverait dépasse les 63 caractères d'un
            // identifiant PostgreSQL.
            $table->unique(['magasin_id', 'periode_debut', 'periode_fin'], 'soumissions_ereporting_periode_unique');
            $table->unique(['magasin_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('soumissions_ereporting');
    }
};
