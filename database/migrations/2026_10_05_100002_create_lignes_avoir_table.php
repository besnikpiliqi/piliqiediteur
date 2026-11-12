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
        Schema::create('lignes_avoir', function (Blueprint $table) {
            $table->id();
            // La ligne d'un avoir émis ne se réécrit ni ne disparaît (voir
            // ProtegeLesDonneesValidees et, en base, GardesDesDocumentsEmis)
            // : restrictOnDelete partout, sauf
            // vers l'avoir, dont seul un brouillon disparaît, avec ses
            // lignes.
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->foreignId('avoir_id');
            // Trace la ligne de facture créditée, sans dépendre d'elle pour
            // les montants : comme pour lignes_facture, tout est un
            // snapshot figé au moment de la création de l'avoir.
            $table->foreignId('ligne_facture_id')->nullable();
            $table->foreignId('produit_id')->nullable();
            // Reprise de la ligne de facture créditée (voir NatureLigne).
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

            $table->index(['magasin_id', 'avoir_id']);

            $table->foreign(['magasin_id', 'avoir_id'])->references(['magasin_id', 'id'])->on('avoirs')->cascadeOnDelete();
            $table->foreign(['magasin_id', 'ligne_facture_id'])->references(['magasin_id', 'id'])->on('lignes_facture')->restrictOnDelete();
            $table->foreign(['magasin_id', 'produit_id'])->references(['magasin_id', 'id'])->on('produits')->restrictOnDelete();
        });

        DeclencheursDeProtection::auMoins('lignes_avoir', 'quantite', 1);

        GardesDesDocumentsEmis::poser('lignes_avoir');
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_avoir');
        GardesDesDocumentsEmis::retirer('lignes_avoir');
    }
};
