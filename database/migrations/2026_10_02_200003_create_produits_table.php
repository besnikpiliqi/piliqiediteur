<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use App\Domain\Stock\Support\GardesDuStock;
use App\Domain\Tenancy\Support\GardesDesTypesDActivite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            // Celui de son dernier achat validé (voir Produit::fournisseur()),
            // même archivé depuis : une fiche utilisée ne s'efface jamais.
            $table->foreignId('fournisseur_id')->nullable();
            $table->string('reference', 60);
            // Clé de doublon de la référence, calculée en PHP (voir
            // Produit::normaliserReference()) : « ABC » et « abc » sont la
            // même référence. La référence reste enregistrée telle que
            // saisie.
            $table->string('reference_normalisee', 60);
            $table->string('code_barres', 50)->nullable();
            $table->string('nom', 200);
            $table->string('taille', 20)->nullable();
            $table->string('couleur', 30)->nullable();
            // Coût moyen unitaire pondéré HT du stock (voir
            // StockLedgerService::receptionner() et
            // StockLedgerService::retournerAuFournisseur()), au dix-millième
            // d'euro : recalculé à chaque réception et à chaque retour au
            // fournisseur, un arrondi au centime à chaque fois finirait par
            // dériver.
            $table->decimal('cmup_ht', 14, 4)->default(0);
            // Le seul prix de vente enregistré, en TTC, tel que l'affiche le
            // magasin ; son HT n'est qu'une conversion affichée (voir
            // Produit::arrondirLePrixDeVente()).
            $table->decimal('prix_vente_ttc', 12, 2)->default(0);
            // Toujours donné à la création : le taux par défaut dépend du
            // territoire du magasin (voir Magasin::tauxTvaParDefaut()).
            $table->decimal('taux_tva', 5, 2);
            // La somme signée du grand livre (mouvements_stock), tenue par
            // la base elle-même : chaque mouvement inséré l'ajoute ici et
            // s'y note comme le dernier compté, et rien d'autre ne change
            // ces deux colonnes (voir GardesDuStock). Entier : une quantité
            // est toujours un nombre d'articles.
            $table->integer('stock_quantite')->default(0);
            $table->unsignedBigInteger('dernier_mouvement_stock_id')->nullable();
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

            $table->unique(['magasin_id', 'id']);
            $table->foreign(['magasin_id', 'fournisseur_id'])->references(['magasin_id', 'id'])->on('fournisseurs')->restrictOnDelete();
        });

        DeclencheursDeProtection::auMoins('produits', 'seuil_alerte', 0);

        // Seule une société qui a des articles a des produits (voir
        // TypeDActivite::aDesArticles()).
        GardesDesTypesDActivite::poser(GardesDesTypesDActivite::PRODUITS);
        GardesDuStock::poserLaNaissanceSansStock();
    }

    public function down(): void
    {
        GardesDesTypesDActivite::retirer(GardesDesTypesDActivite::PRODUITS);
        Schema::dropIfExists('produits');
        GardesDuStock::retirerLaNaissanceSansStock();
    }
};
