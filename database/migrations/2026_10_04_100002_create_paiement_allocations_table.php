<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paiement_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            $table->foreignId('paiement_id')->constrained('paiements')->cascadeOnDelete();
            // restrictOnDelete, pas cascadeOnDelete : une facture ne se
            // supprime de toute façon jamais (voir FacturePolicy), mais
            // on ne veut surtout pas qu'une suppression quelconque efface
            // silencieusement la trace d'un encaissement.
            $table->foreignId('facture_id')->constrained('factures')->restrictOnDelete();
            $table->decimal('montant_alloue', 12, 2);
            $table->timestamps();

            // Un paiement ne s'alloue qu'une fois par facture : pour
            // ajouter un complément, on enregistre un nouveau paiement,
            // pas une deuxième ligne d'allocation sur le même couple.
            $table->unique(['paiement_id', 'facture_id']);
            $table->index(['magasin_id', 'facture_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paiement_allocations');
    }
};
