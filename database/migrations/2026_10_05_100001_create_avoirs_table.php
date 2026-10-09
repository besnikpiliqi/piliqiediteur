<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avoirs', function (Blueprint $table) {
            $table->id();
            // restrictOnDelete partout : un avoir émis ne se réécrit ni ne
            // disparaît (voir ProtegeLesDonneesValidees), pas même par la
            // suppression d'une fiche qu'il référence.
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            // Un avoir appartient à un client, pas à une facture : il peut
            // regrouper des lignes de plusieurs factures du même client
            // (voir CreerAvoirAction). La facture d'origine de chaque ligne
            // est connue par lignes_avoir.ligne_facture_id.
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            // Clôture de caisse qui a déduit cet avoir à un particulier des
            // ventes de sa journée (voir CloturerCaisseAction) -- jamais
            // renseignée pour un client professionnel, comme pour une
            // facture (voir factures.cloture_caisse_id). Seule colonne
            // encore écrite sur un avoir émis : l'étape des clôtures la
            // supprimera.
            $table->foreignId('cloture_caisse_id')->nullable()->constrained('clotures_caisse')->nullOnDelete();
            $table->foreignId('cree_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('numero', 40)->nullable();
            $table->string('statut', 20)->default('brouillon');
            $table->timestamp('date_emission')->nullable();
            $table->string('devise', 3)->default('EUR');
            $table->text('motif')->nullable();
            $table->decimal('total_ht', 12, 2)->default(0);
            $table->decimal('total_tva', 12, 2)->default(0);
            $table->decimal('total_ttc', 12, 2)->default(0);
            $table->timestamps();
            // PAS de softDeletes() ici, volontairement : un avoir est, au
            // même titre qu'une facture, un document fiscal dont la loi
            // impose la conservation (voir AvoirPolicy).

            $table->unique(['magasin_id', 'numero']);
            $table->index(['magasin_id', 'client_id']);
            $table->index(['magasin_id', 'cloture_caisse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avoirs');
    }
};
