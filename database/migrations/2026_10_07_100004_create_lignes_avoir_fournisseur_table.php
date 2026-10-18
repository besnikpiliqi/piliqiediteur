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
            // La ligne d'achat retournée, toujours : un avoir fournisseur ne
            // retourne qu'un produit d'une facture d'achat (voir
            // CreerAvoirFournisseurAction), qu'il ne modifie jamais. Comme
            // pour lignes_avoir, le reste de la ligne est une copie figée à
            // la création de l'avoir. restrictOnDelete : une ligne d'achat
            // retournée ne s'efface jamais sous son avoir.
            $table->foreignId('ligne_achat_id')->constrained('lignes_achat')->restrictOnDelete();
            // Celui de la ligne d'achat retournée, archivé compris : une
            // fiche utilisée ne s'efface jamais.
            $table->foreignId('produit_id')->constrained('produits')->restrictOnDelete();
            $table->string('description', 255);
            $table->string('reference', 60)->nullable();
            // Nombre entier d'articles (voir QuantiteEntiere).
            $table->unsignedInteger('quantite');
            // Un achat part du PU HT du fournisseur, sans PU TTC : la TVA de
            // chaque taux se calcule sur le total HT de ses lignes, puis se
            // répartit entre elles (voir CalculateurLigne::chiffrerUnAchat()).
            $table->decimal('prix_unitaire_ht', 12, 2);
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
