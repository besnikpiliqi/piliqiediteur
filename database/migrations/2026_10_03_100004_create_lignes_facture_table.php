<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignes_facture', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            $table->foreignId('facture_id')->constrained('factures')->cascadeOnDelete();
            $table->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            // Une ligne vend un produit du catalogue ou un service du magasin
            // (voir Service), jamais les deux ni une ligne libre (voir
            // ConstructeurLigneDocument).
            $table->foreignId('service_id')->nullable()->constrained('services')->nullOnDelete();
            // Bien ou service (voir NatureLigne) : la catégorie d'opération de
            // la facture et l'e-reporting en dépendent.
            $table->string('nature', 10);
            $table->string('description', 255);
            $table->string('reference', 60)->nullable();
            // Nombre entier d'articles (voir QuantiteEntiere).
            $table->unsignedInteger('quantite');
            // Une vente part de son PU TTC ; son PU HT en est la
            // conversion au centime, article par article (voir
            // CalculateurLigne::montantsDeVente()).
            $table->decimal('prix_unitaire_ht', 12, 2);
            $table->decimal('prix_unitaire_ttc', 12, 2);
            $table->decimal('taux_tva', 5, 2);
            $table->decimal('remise_pourcentage', 5, 2)->default(0);
            $table->decimal('montant_ht', 12, 2);
            $table->decimal('montant_tva', 12, 2);
            $table->decimal('montant_ttc', 12, 2);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->index(['magasin_id', 'facture_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_facture');
    }
};
