<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use App\Domain\Facturation\Support\GardesDesDocumentsEmis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignes_facture', function (Blueprint $table) {
            $table->id();
            // La ligne d'une facture émise ne se réécrit ni ne disparaît (voir
            // ProtegeLesDonneesValidees et, en base, GardesDesDocumentsEmis)
            // : restrictOnDelete partout, sauf
            // vers la facture, dont seul un brouillon se supprime, avec ses
            // lignes.
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->foreignId('facture_id');
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

            $table->index(['magasin_id', 'facture_id']);

            $table->unique(['magasin_id', 'id']);
            $table->foreign(['magasin_id', 'facture_id'])->references(['magasin_id', 'id'])->on('factures')->cascadeOnDelete();
            $table->foreign(['magasin_id', 'produit_id'])->references(['magasin_id', 'id'])->on('produits')->restrictOnDelete();
            $table->foreign(['magasin_id', 'service_id'])->references(['magasin_id', 'id'])->on('services')->restrictOnDelete();
        });

        DeclencheursDeProtection::auMoins('lignes_facture', 'quantite', 1);

        GardesDesDocumentsEmis::poser('lignes_facture');
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_facture');
        GardesDesDocumentsEmis::retirer('lignes_facture');
    }
};
