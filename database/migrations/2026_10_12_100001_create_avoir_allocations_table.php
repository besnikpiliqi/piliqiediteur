<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ce que chaque avoir crédite de chaque facture : on n'y fait qu'ajouter.
 * Une imputation naît pendant l'émission de l'avoir (voir
 * ImputerAvoirSurSesFacturesAction), pendant celle de la facture qui
 * absorbe un crédit (voir AppliquerAvoirsDisponiblesAction), ou pendant
 * l'enregistrement d'un paiement, dont le crédit solde ce qui reste dû des
 * factures qu'il règle (voir EnregistrerPaiementAction, paiement_id) ; elle
 * figure dans le contenu signé de ce document ou de ce paiement au
 * registre fiscal. La base refuse toute modification, toute suppression et
 * tout vidage (voir DeclencheursDeProtection).
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
            $table->foreignId('paiement_id')->nullable()->constrained('paiements')->restrictOnDelete();
            $table->decimal('montant_alloue', 12, 2);
            $table->timestamps();

            // Un paiement n'impute un avoir qu'une fois sur une facture.
            $table->unique(['avoir_id', 'facture_id', 'paiement_id']);
            $table->index(['magasin_id', 'facture_id']);
        });

        // Une émission n'impute un avoir qu'une fois sur une facture (deux
        // valeurs nulles ne se heurtent pas dans l'index unique ci-dessus).
        // Un paiement peut l'y imputer de nouveau, quand une
        // contre-passation a rouvert la facture (voir
        // ContrePasserPaiementAction).
        DB::statement('create unique index avoir_allocations_emission_unique on avoir_allocations (avoir_id, facture_id) where paiement_id is null');
        DeclencheursDeProtection::ajoutSeul('avoir_allocations');
    }

    public function down(): void
    {
        Schema::dropIfExists('avoir_allocations');
        DeclencheursDeProtection::retirer('avoir_allocations');
    }
};
