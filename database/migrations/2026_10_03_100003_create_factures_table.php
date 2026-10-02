<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('devis_id')->nullable()->constrained('devis')->nullOnDelete();
            // Qui a créé la facture : nécessaire pour restreindre la
            // visibilité d'un vendeur à ses propres ventes par défaut
            // (voir FacturePolicy et FACTURE_VIEW_TOUTES).
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('numero', 40)->nullable();
            $table->string('statut', 20)->default('brouillon');
            $table->timestamp('date_emission')->nullable();
            $table->date('date_echeance')->nullable();
            $table->string('devise', 3)->default('EUR');
            $table->text('conditions_paiement')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('total_ht', 12, 2)->default(0);
            $table->decimal('total_tva', 12, 2)->default(0);
            $table->decimal('total_ttc', 12, 2)->default(0);
            // montant_restant n'est PAS stocké : toujours total_ttc -
            // montant_paye, recalculable de façon fiable (voir Facture::
            // montantRestant()).
            $table->decimal('montant_paye', 12, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['magasin_id', 'numero']);
            $table->index(['magasin_id', 'statut']);
            $table->index(['magasin_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factures');
    }
};
