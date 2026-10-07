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
            // Une ligne est un produit, un service du catalogue (voir
            // Service) ou une ligne libre -- jamais un produit et un service
            // à la fois (voir ConstructeurLigneDocument).
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            $table->string('description', 255);
            $table->string('reference', 60)->nullable();
            // Nombre entier d'articles (voir QuantiteEntiere).
            $table->unsignedInteger('quantite');
            // Les deux prix unitaires, au centime : celui que désigne
            // prix_reference est le prix saisi qui fait foi pour le calcul
            // de la ligne, l'autre n'en est que la conversion (voir
            // PrixReference et CalculateurLigne).
            $table->decimal('prix_unitaire_ht', 12, 2);
            $table->decimal('prix_unitaire_ttc', 12, 2);
            $table->string('prix_reference', 3);
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
