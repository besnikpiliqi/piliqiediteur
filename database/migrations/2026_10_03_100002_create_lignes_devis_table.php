<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignes_devis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            $table->foreignId('devis_id')->constrained('devis')->cascadeOnDelete();
            // nullable : ligne libre (service hors catalogue) ou produit
            // supprimé depuis -- la ligne garde son propre snapshot, elle
            // ne dépend jamais des valeurs actuelles du produit.
            $table->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            $table->string('description', 255);
            $table->string('reference', 60)->nullable();
            $table->decimal('quantite', 12, 3);
            $table->decimal('prix_unitaire_ht', 12, 2);
            $table->decimal('taux_tva', 5, 2);
            $table->decimal('remise_pourcentage', 5, 2)->default(0);
            $table->decimal('montant_ht', 12, 2);
            $table->decimal('montant_tva', 12, 2);
            $table->decimal('montant_ttc', 12, 2);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->index(['magasin_id', 'devis_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_devis');
    }
};
