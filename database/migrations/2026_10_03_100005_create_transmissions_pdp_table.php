<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transmissions_pdp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            // Polymorphique : une facture ou une soumission d'e-reporting
            // (voir les morphMany transmissionsPdp()) -- même mécanique de
            // suivi/historique pour toutes.
            $table->nullableMorphs('transmissible');
            $table->string('direction', 10); // outbound, inbound
            $table->string('prestataire_pdp', 60);
            $table->string('transmission_externe_id')->nullable();
            $table->string('format', 10)->nullable(); // facturx, ereporting (voir FakePdpGateway)
            $table->string('statut', 20)->default('soumise');
            $table->string('chemin_fichier_archive')->nullable();
            $table->string('code_erreur', 60)->nullable();
            $table->text('message_erreur')->nullable();
            $table->timestamp('soumise_le')->nullable();
            $table->timestamp('statut_maj_le')->nullable();
            $table->timestamps();

            $table->unique(['prestataire_pdp', 'transmission_externe_id']);
            $table->index(['magasin_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transmissions_pdp');
    }
};
