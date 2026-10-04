<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avoirs', function (Blueprint $table) {
            // Un avoir n'appartient plus à UNE facture : il peut regrouper
            // des lignes de plusieurs factures différentes d'un même
            // client (un client qui retourne des articles de plusieurs
            // achats en une seule fois), voir le docblock de
            // CreerAvoirAction. La traçabilité par ligne d'origine
            // (lignes_avoir.ligne_facture_id) suffit déjà à savoir de
            // quelle facture provient chaque produit retourné.
            $table->dropIndex(['magasin_id', 'facture_id']);
            $table->dropForeign(['facture_id']);
            $table->dropColumn('facture_id');
            $table->foreignId('client_id')->after('magasin_id')->constrained('clients')->restrictOnDelete();
            $table->index(['magasin_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::table('avoirs', function (Blueprint $table) {
            $table->dropIndex(['magasin_id', 'client_id']);
            $table->dropForeign(['client_id']);
            $table->dropColumn('client_id');
            $table->foreignId('facture_id')->after('magasin_id')->constrained('factures')->restrictOnDelete();
            $table->index(['magasin_id', 'facture_id']);
        });
    }
};
