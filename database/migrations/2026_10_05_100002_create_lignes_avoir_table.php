<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignes_avoir', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            $table->foreignId('avoir_id')->constrained('avoirs')->cascadeOnDelete();
            // Trace la ligne de facture créditée, sans dépendre d'elle pour
            // les montants : comme pour lignes_facture, tout est un
            // snapshot figé au moment de la création de l'avoir.
            $table->foreignId('ligne_facture_id')->nullable()->constrained('lignes_facture')->nullOnDelete();
            $table->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            // Reprise de la ligne de facture créditée (voir NatureLigne).
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

            $table->index(['magasin_id', 'avoir_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_avoir');
    }
};
