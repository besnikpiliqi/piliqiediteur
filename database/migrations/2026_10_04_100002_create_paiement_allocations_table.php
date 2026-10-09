<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ce que chaque paiement règle de chaque facture : on n'y fait qu'ajouter,
 * comme aux paiements (voir la migration create_paiements_table). Chaque
 * imputation figure dans le contenu signé de son paiement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paiement_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->foreignId('paiement_id')->constrained('paiements')->restrictOnDelete();
            $table->foreignId('facture_id')->constrained('factures')->restrictOnDelete();
            $table->decimal('montant_alloue', 12, 2);
            $table->timestamps();

            // Un paiement ne s'alloue qu'une fois par facture : pour
            // ajouter un complément, on enregistre un nouveau paiement,
            // pas une deuxième ligne d'allocation sur le même couple.
            $table->unique(['paiement_id', 'facture_id']);
            $table->index(['magasin_id', 'facture_id']);
        });

        DeclencheursDeProtection::ajoutSeul('paiement_allocations');
    }

    public function down(): void
    {
        Schema::dropIfExists('paiement_allocations');
    }
};
