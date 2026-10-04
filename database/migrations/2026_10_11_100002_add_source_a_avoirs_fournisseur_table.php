<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('avoirs_fournisseur', function (Blueprint $table) {
            // Pure traçabilité (voir SourceAvoirFournisseur) : la création
            // reste aujourd'hui entièrement manuelle, ce champ anticipe une
            // future synchronisation automatique par API.
            $table->string('source', 20)->default('manuelle')->after('cree_par');
        });
    }

    public function down(): void
    {
        Schema::table('avoirs_fournisseur', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
