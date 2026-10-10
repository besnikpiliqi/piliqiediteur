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
            // Celui de son dernier achat validé (voir Produit::fournisseur()),
            // même archivé depuis : une fiche utilisée ne s'efface jamais.
            $table->foreignId('fournisseur_id')->nullable()->constrained('fournisseurs')->restrictOnDelete();
            $table->string('reference', 60);
            // Clé de doublon de la référence, calculée en PHP (voir
            // Produit::normaliserReference()) : « ABC » et « abc » sont la
            // même référence, sur les deux moteurs. La référence reste
            // enregistrée telle que saisie.
            $table->string('reference_normalisee', 60);
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
            // Toujours donné à la création : le taux par défaut dépend du
            // territoire du magasin (voir Magasin::tauxTvaParDefaut()).
            $table->decimal('taux_tva', 5, 2);
            // Cache dénormalisé : la source de vérité est le ledger
            // mouvements_stock. Ne JAMAIS écrire cette colonne ailleurs que
            // dans StockLedgerService (voir app/Domain/Stock/Services).
            // Entier : une quantité est toujours un nombre d'articles.
            $table->integer('stock_quantite')->default(0);
            $table->boolean('suivi_stock')->default(true);
            $table->unsignedInteger('seuil_alerte')->default(0);
            $table->timestamps();
            // Un produit ne se supprime pas : il s'archive (voir
            // ProduitResource), reste sur ses documents et dans la valeur du
            // stock, et se restaure.
            $table->softDeletes();

            // Une référence est unique dans le magasin, archivés compris,
            // sans tenir compte de la casse.
            $table->unique(['magasin_id', 'reference_normalisee']);
            $table->index(['magasin_id', 'code_barres']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('produits');
    }
};
