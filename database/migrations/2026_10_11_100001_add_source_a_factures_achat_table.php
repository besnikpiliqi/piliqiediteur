<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factures_achat', function (Blueprint $table) {
            // Même pattern que fournisseurs.source : une facture saisie à
            // la main reste modifiable même validée (voir
            // FactureAchat::estModifiable()), une facture récupérée par API
            // ne l'est jamais.
            $table->string('source', 20)->default('manuelle')->after('cree_par');
        });
    }

    public function down(): void
    {
        Schema::table('factures_achat', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
