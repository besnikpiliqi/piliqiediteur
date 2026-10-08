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
            // Nullable au niveau base (même logique que lignes_facture : un
            // produit supprimé ne doit jamais effacer l'historique d'achat),
            // mais obligatoire en pratique : CreerFactureAchatAction exige
            // un produit_id sur chaque ligne, la traçabilité pour l'avoir
            // fournisseur (voir LigneAchat::quantiteRetournableRestante())
            // n'ayant de sens que pour un produit suivi en stock.
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

            $table->index(['magasin_id', 'facture_achat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_achat');
    }
};
