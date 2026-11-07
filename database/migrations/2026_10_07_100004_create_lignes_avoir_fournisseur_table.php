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
            // Celui de la ligne d'achat retournée, six décimales au plus
            // (voir PrixDAchat).
            $table->decimal('prix_unitaire_ht', 18, 6);
            $table->decimal('taux_tva', 5, 2);
            $table->decimal('remise_pourcentage', 5, 2)->default(0);
            // Le HT de la ligne tel que l'écrit la note d'avoir du
            // fournisseur, quand la personne l'a saisi ; vide, celui que
            // PILIQI propose pour ce retour (voir LigneAchat::montantsDuRetour()).
            // Gardé du brouillon à l'émission, qui arrête les montants.
            $table->decimal('montant_ht_saisi', 12, 2)->nullable();
            // Le HT retenu (le saisi, sinon le proposé), et la part de la
            // TVA retenue de son taux (voir la migration
            // create_taux_avoirs_fournisseur_table).
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
