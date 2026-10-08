<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignes_avoir_fournisseur', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            $table->foreignId('avoir_fournisseur_id')->constrained('avoirs_fournisseur')->cascadeOnDelete();
            // Trace la ligne d'achat d'origine (produit + facture d'achat)
            // SANS jamais la modifier : voir le docblock de
            // CreerAvoirFournisseurAction. Comme pour lignes_avoir, tout
            // est un snapshot figé au moment de la création de l'avoir.
            $table->foreignId('ligne_achat_id')->nullable()->constrained('lignes_achat')->nullOnDelete();
            $table->foreignId('produit_id')->nullable()->constrained('produits')->nullOnDelete();
            $table->string('description', 255);
            $table->string('reference', 60)->nullable();
            // Nombre entier d'articles (voir QuantiteEntiere).
            $table->unsignedInteger('quantite');
            // Les prix unitaires tels qu'ils ont été saisis, au centime :
            // celui que désigne prix_reference fait foi pour le calcul de
            // la ligne. Un prix TTC a aussi son PU HT (sa conversion, comme
            // sur la fiche du produit) ; un prix HT n'a pas de PU TTC, la
            // TVA se calculant sur le HT de la ligne (voir PrixReference et
            // CalculateurLigne).
            $table->decimal('prix_unitaire_ht', 12, 2);
            $table->decimal('prix_unitaire_ttc', 12, 2)->nullable();
            $table->string('prix_reference', 3);
            $table->decimal('taux_tva', 5, 2);
            $table->decimal('remise_pourcentage', 5, 2)->default(0);
            $table->decimal('montant_ht', 12, 2);
            $table->decimal('montant_tva', 12, 2);
            $table->decimal('montant_ttc', 12, 2);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->index(['magasin_id', 'avoir_fournisseur_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_avoir_fournisseur');
    }
};
