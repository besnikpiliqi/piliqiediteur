<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignes_devis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->foreignId('devis_id');
            // nullable : une ligne de service n'a pas de produit. La ligne
            // garde son propre snapshot, elle ne dépend jamais des valeurs
            // actuelles du produit, qui reste lié, archivé compris : une
            // fiche utilisée ne s'efface jamais.
            $table->foreignId('produit_id')->nullable();
            // Une ligne vend un produit du catalogue ou un service du magasin
            // (voir Service), jamais les deux ni une ligne libre (voir
            // ConstructeurLigneDocument).
            $table->foreignId('service_id')->nullable();
            // Bien ou service (voir NatureLigne) : la catégorie d'opération de
            // la facture et l'e-reporting en dépendent.
            $table->string('nature', 10);
            $table->string('description', 255);
            $table->string('reference', 60)->nullable();
            // Nombre entier d'articles (voir QuantiteEntiere).
            $table->unsignedInteger('quantite');
            // Une vente part de son PU TTC, au taux que comprend ce prix
            // (taux_tva_du_prix) : celui de la ligne (taux_tva), sauf pour un
            // produit vendu hors de la TVA française, dont la ligne est à
            // 0 % et le PU TTC du catalogue comprend encore la TVA, retirée
            // du prix payé (voir ContexteDeVente). Son PU HT en est la
            // conversion, enregistrée au millionième d'euro (voir
            // CalculateurLigne::prixUnitaireHtExact()) et affichée arrondie
            // au centime (voir CalculateurLigne::prixUnitaireAffiche()) ;
            // ses montants se calculent taux par taux sur le TTC des lignes
            // du document (voir CalculateurLigne::chiffrerUneVente()).
            $table->decimal('prix_unitaire_ht', 18, 6);
            $table->decimal('prix_unitaire_ttc', 12, 2);
            $table->decimal('taux_tva', 5, 2);
            $table->decimal('taux_tva_du_prix', 5, 2);
            $table->decimal('remise_pourcentage', 5, 2)->default(0);
            $table->decimal('montant_ht', 12, 2);
            $table->decimal('montant_tva', 12, 2);
            $table->decimal('montant_ttc', 12, 2);
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->index(['magasin_id', 'devis_id']);

            $table->foreign(['magasin_id', 'devis_id'])->references(['magasin_id', 'id'])->on('devis')->cascadeOnDelete();
            $table->foreign(['magasin_id', 'produit_id'])->references(['magasin_id', 'id'])->on('produits')->restrictOnDelete();
            $table->foreign(['magasin_id', 'service_id'])->references(['magasin_id', 'id'])->on('services')->restrictOnDelete();
        });

        DeclencheursDeProtection::auMoins('lignes_devis', 'quantite', 1);
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_devis');
    }
};
