<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignes_inventaire_ouverture', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            $table->foreignId('inventaire_ouverture_id')->constrained('inventaires_ouverture')->cascadeOnDelete();
            // Le produit que la ligne fait entrer en stock : un produit du
            // magasin jamais reçu, ou celui que la validation a créé depuis
            // la fiche de la ligne (voir nouveau_produit). Il reste lié,
            // archivé compris : une fiche utilisée ne s'efface jamais.
            $table->foreignId('produit_id')->nullable()->constrained('produits')->restrictOnDelete();
            // Fiche du produit que cette ligne d'un brouillon créera à la
            // validation (voir ValiderInventaireOuvertureAction) ; une
            // ligne a toujours soit produit_id, soit nouveau_produit (voir
            // LigneInventaireOuverture).
            $table->json('nouveau_produit')->nullable();
            $table->string('description', 255);
            $table->string('reference', 60)->nullable();
            // Nombre entier d'articles, au moins 1 (voir QuantiteEntiere).
            $table->unsignedInteger('quantite');
            // Le coût unitaire hors taxe du stock existant, saisi au
            // centime : le coût moyen de départ du produit, sans TVA ni
            // fournisseur.
            $table->decimal('cout_unitaire_ht', 12, 2);
            // Quantité x coût unitaire, exacte au centime.
            $table->decimal('valeur_ht', 14, 2);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->index(['magasin_id', 'inventaire_ouverture_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_inventaire_ouverture');
    }
};
