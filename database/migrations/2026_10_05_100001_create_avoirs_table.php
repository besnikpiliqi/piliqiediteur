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
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            // restrictOnDelete : une facture ne se supprime de toute façon
            // jamais, mais on ne veut surtout pas qu'une suppression
            // quelconque efface silencieusement la trace d'un avoir.
            $table->foreignId('facture_id')->constrained('factures')->restrictOnDelete();
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
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
            $table->index(['magasin_id', 'facture_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avoirs');
    }
};
