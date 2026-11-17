<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les comptes du bandeau « Vous êtes : » d'une copie (lot DEMO-COPIE, C.8
 * et F.3) : seulement les comptes de la société mise en avant du modèle,
 * écrits par le semis du modèle, puis ceux que le visiteur crée dans sa
 * copie, chacun avec le libellé de son rôle, le rôle que ce libellé décrit
 * (le bandeau lit le rôle du compte tel qu'il est et ne garde le libellé
 * que tant qu'il n'a pas changé, voir RoleAuBandeau) et le groupe où il
 * est entré. Cette table n'existe que dans les bases du site de démonstration
 * (migrations de database/migrations-demonstration), jamais dans la base
 * réelle. Le compte est une clé composée (societe_id, user_id) vers les
 * comptes de la même société.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comptes_du_bandeau', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->foreignId('user_id');
            $table->string('libelle_du_role', 120);
            $table->string('role', 40);
            $table->string('groupe', 120);
            $table->unsignedInteger('ordre');
            $table->unique('user_id');
            $table->index(['societe_id', 'ordre']);
            $table->foreign(['societe_id', 'user_id'])->references(['societe_id', 'id'])->on('users')->restrictOnDelete();
        });

        DB::statement('alter table comptes_du_bandeau add constraint comptes_du_bandeau_ordre_positif check (ordre >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('comptes_du_bandeau');
    }
};
