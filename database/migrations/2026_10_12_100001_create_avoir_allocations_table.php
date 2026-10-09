<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursAjoutSeul;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ce que chaque avoir crédite de chaque facture : on n'y fait qu'ajouter.
 * Une imputation naît toujours pendant une émission, celle de l'avoir
 * (voir ImputerAvoirSurSesFacturesAction) ou celle de la facture qui
 * absorbe un crédit (voir AppliquerAvoirsDisponiblesAction), et figure
 * dans le contenu signé de ce document au registre fiscal ; la base refuse
 * toute modification et toute suppression (voir DeclencheursAjoutSeul).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avoir_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->foreignId('avoir_id')->constrained('avoirs')->restrictOnDelete();
            $table->foreignId('facture_id')->constrained('factures')->restrictOnDelete();
            $table->decimal('montant_alloue', 12, 2);
            $table->timestamps();

            // Un avoir ne s'alloue qu'une fois par facture : pour
            // compléter, on applique le reliquat d'un autre avoir, pas une
            // deuxième ligne d'allocation sur le même couple (même
            // contrainte que paiement_allocations).
            $table->unique(['avoir_id', 'facture_id']);
            $table->index(['magasin_id', 'facture_id']);
        });

        DeclencheursAjoutSeul::poser('avoir_allocations');
    }

    public function down(): void
    {
        Schema::dropIfExists('avoir_allocations');
    }
};
