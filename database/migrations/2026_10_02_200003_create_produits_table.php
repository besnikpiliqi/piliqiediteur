<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            $table->foreignId('produit_parent_id')->nullable()->constrained('produits')->nullOnDelete();
            $table->foreignId('fournisseur_id')->nullable()->constrained('fournisseurs')->nullOnDelete();
            $table->string('reference', 60);
            $table->string('code_barres', 50)->nullable();
            $table->string('nom', 200);
            $table->string('taille', 20)->nullable();
            $table->string('couleur', 30)->nullable();
            // Coût moyen unitaire pondéré HT du stock (voir
            // StockLedgerService::receptionner()), au dix-millième d'euro :
            // recalculé à chaque réception, un arrondi au centime à chaque
            // fois finirait par dériver.
            $table->decimal('cmup_ht', 14, 4)->default(0);
            // Le prix de vente est saisi et enregistré en TTC, tel que
            // l'affiche le magasin ; le HT n'en est que la conversion au
            // centime (voir Produit::synchroniserPrixDeVente()).
            $table->decimal('prix_vente_ttc', 12, 2)->default(0);
            $table->decimal('prix_vente_ht', 12, 2)->default(0);
            $table->decimal('taux_tva', 5, 2)->default(20.00);
            // Cache dénormalisé : la source de vérité est le ledger
            // mouvements_stock. Ne JAMAIS écrire cette colonne ailleurs que
            // dans StockLedgerService (voir app/Domain/Stock/Services).
            // Entier : une quantité est toujours un nombre d'articles.
            $table->integer('stock_quantite')->default(0);
            $table->boolean('suivi_stock')->default(true);
            $table->unsignedInteger('seuil_alerte')->default(0);
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['magasin_id', 'reference']);
            $table->index(['magasin_id', 'code_barres']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produits');
    }
};
