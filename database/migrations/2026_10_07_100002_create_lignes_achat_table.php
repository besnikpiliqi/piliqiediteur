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
        Schema::create('lignes_achat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->foreignId('facture_achat_id');
            // Le produit du catalogue que la ligne reçoit (voir
            // ConstructeurLigneAchat) : la traçabilité pour l'avoir
            // fournisseur (voir LigneAchat::quantiteRetournableRestante())
            // n'a de sens que pour lui. Il reste lié, archivé compris : une
            // fiche utilisée ne s'efface jamais. Vide seulement tant que la
            // ligne d'un brouillon garde la fiche d'un nouveau produit, pas
            // encore créé (voir nouveau_produit).
            $table->foreignId('produit_id')->nullable();
            // Fiche du produit que cette ligne d'un brouillon créera à la
            // validation (voir ConstructeurLigneAchat::creerLesNouveauxProduits()) ;
            // une ligne a toujours soit produit_id, soit nouveau_produit
            // (voir LigneAchat).
            $table->json('nouveau_produit')->nullable();
            $table->string('description', 255);
            $table->string('reference', 60)->nullable();
            // Nombre entier d'articles (voir QuantiteEntiere).
            $table->unsignedInteger('quantite');
            // Le PU HT tel que le fournisseur l'écrit, six décimales au plus,
            // jamais arrondi (voir PrixDAchat) : un achat part du PU HT du
            // fournisseur, sans PU TTC (voir
            // CalculateurLigne::chiffrerUnAchat()).
            $table->decimal('prix_unitaire_ht', 18, 6);
            $table->decimal('taux_tva', 5, 2);
            $table->decimal('remise_pourcentage', 5, 2)->default(0);
            // Le HT de la ligne tel que l'écrit la facture du fournisseur,
            // quand la personne l'a saisi ; vide, celui que PILIQI propose
            // (quantité x PU HT, remise déduite, arrondi une fois).
            $table->decimal('montant_ht_saisi', 12, 2)->nullable();
            // Le HT retenu (le saisi, sinon le proposé), et la part de la
            // TVA retenue de son taux (voir la migration
            // create_taux_factures_achat_table), répartie par cumul.
            $table->decimal('montant_ht', 12, 2);
            $table->decimal('montant_tva', 12, 2);
            $table->decimal('montant_ttc', 12, 2);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->index(['magasin_id', 'facture_achat_id']);

            $table->unique(['magasin_id', 'id']);
            $table->foreign(['magasin_id', 'facture_achat_id'])->references(['magasin_id', 'id'])->on('factures_achat')->cascadeOnDelete();
            $table->foreign(['magasin_id', 'produit_id'])->references(['magasin_id', 'id'])->on('produits')->restrictOnDelete();
        });

        DeclencheursDeProtection::auMoins('lignes_achat', 'quantite', 1);
        GardesDuStock::poserLaCoherenceDesAchats();
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_achat');
        GardesDuStock::retirerLaCoherenceDesAchats();
    }
};
