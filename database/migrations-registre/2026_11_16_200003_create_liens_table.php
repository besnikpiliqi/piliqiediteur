<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les liens de chaque copie (lot DEMO-COPIE, C.1 et C.4) : seulement
 * l'empreinte SHA-256 du secret, jamais le secret, sa génération et sa fin.
 * Un renouvellement retire l'ancien lien (retire_le) : il ne mène plus
 * nulle part.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('copie_id')->constrained('copies')->restrictOnDelete();
            $table->char('empreinte', 64)->unique();
            $table->unsignedInteger('generation');
            $table->timestamp('fin');
            $table->timestamp('cree_le');
            $table->timestamp('retire_le')->nullable();
            $table->unique(['copie_id', 'generation']);
        });

        DB::statement("alter table liens add constraint liens_empreinte_hexadecimale check (empreinte ~ '^[0-9a-f]{64}$')");
        DB::statement('create unique index liens_un_seul_actif on liens (copie_id) where retire_le is null');
    }

    public function down(): void
    {
        Schema::dropIfExists('liens');
    }
};
