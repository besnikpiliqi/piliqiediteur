<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignes_achat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            $table->foreignId('facture_achat_id')->constrained('factures_achat')->cascadeOnDelete();
            // Le produit du catalogue que la ligne reçoit (voir
            // ConstructeurLigneAchat) : la traçabilité pour l'avoir
            // fournisseur (voir LigneAchat::quantiteRetournableRestante())
            // n'a de sens que pour lui. Il reste lié, archivé compris : une
            // fiche utilisée ne s'efface jamais. Vide seulement tant que la
            // ligne d'un brouillon garde la fiche d'un nouveau produit, pas
            // encore créé (voir nouveau_produit).
            $table->foreignId('produit_id')->nullable()->constrained('produits')->restrictOnDelete();
            // Fiche du produit que cette ligne d'un brouillon créera à la
            // validation (voir ConstructeurLigneAchat::creerLesNouveauxProduits()) ;
            // une ligne a toujours soit produit_id, soit nouveau_produit
            // (voir LigneAchat).
            $table->json('nouveau_produit')->nullable();
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

            $table->index(['magasin_id', 'facture_achat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_achat');
    }
};
