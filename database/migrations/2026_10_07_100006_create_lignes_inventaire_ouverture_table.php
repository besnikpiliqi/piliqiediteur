<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use App\Domain\Stock\Support\GardesDuStock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignes_inventaire_ouverture', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->foreignId('inventaire_ouverture_id');
            // Le produit que la ligne fait entrer en stock : un produit du
            // magasin jamais reçu, ou celui que la validation a créé depuis
            // la fiche de la ligne (voir nouveau_produit). Il reste lié,
            // archivé compris : une fiche utilisée ne s'efface jamais.
            $table->foreignId('produit_id')->nullable();
            // Fiche du produit que cette ligne d'un brouillon créera à la
            // validation (voir ValiderInventaireOuvertureAction) ; une
            // ligne a toujours soit produit_id, soit nouveau_produit (voir
            // LigneInventaireOuverture).
            $table->json('nouveau_produit')->nullable();
            $table->string('description', 255);
            $table->string('reference', 60)->nullable();
            // Nombre entier d'articles, au moins 1 (voir QuantiteEntiere).
            $table->unsignedInteger('quantite');
            // Le coût unitaire hors taxe du stock existant, tel que saisi,
            // six décimales au plus, jamais arrondi (voir PrixDAchat) : le
            // coût moyen de départ du produit, sans TVA ni fournisseur.
            $table->decimal('cout_unitaire_ht', 18, 6);
            // Le produit suit-il son stock ? Lu sur sa fiche à chaque
            // enregistrement du brouillon, puis, définitivement, à la
            // validation, sous le verrou du produit (voir
            // ConstructeurLigneInventaire) : seule une ligne qui le suit
            // fait entrer son stock ; une autre ne donne au produit que son
            // coût de départ.
            $table->boolean('suivi_stock');
            // La valeur du stock que la ligne fait entrer : quantité x coût
            // unitaire, arrondie une fois au centime, ou zéro pour un
            // produit qui ne suit pas son stock.
            $table->decimal('valeur_ht', 14, 2);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->index(['magasin_id', 'inventaire_ouverture_id']);

            $table->foreign(['magasin_id', 'inventaire_ouverture_id'])->references(['magasin_id', 'id'])->on('inventaires_ouverture')->cascadeOnDelete();
            $table->foreign(['magasin_id', 'produit_id'])->references(['magasin_id', 'id'])->on('produits')->restrictOnDelete();
        });

        DeclencheursDeProtection::auMoins('lignes_inventaire_ouverture', 'quantite', 1);

        // Dernière table que lit la garde de la provenance de chaque
        // mouvement du grand livre (voir GardesDuStock::poserLaProvenance()).
        GardesDuStock::poserLaProvenance();
    }

    public function down(): void
    {
        GardesDuStock::retirerLaProvenance();
        Schema::dropIfExists('lignes_inventaire_ouverture');
    }
};
