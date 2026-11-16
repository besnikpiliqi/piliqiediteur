<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les archives de la caisse des copies (lot DEMO-COPIE, C.18) : faites
 * avant toute suppression d'une copie (registre, journal des événements,
 * tickets Z, totaux perpétuels, sommaire signé avec la clé de caisse de
 * démonstration), gardées six ans (BOFiP § 155), puis effacées par
 * demonstration:purger-les-archives. Seule la référence de la copie y est,
 * jamais une donnée de la vraie société.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archives', function (Blueprint $table) {
            $table->id();
            $table->char('reference', 32)->index();
            $table->string('motif', 30);
            $table->string('chemin', 255)->unique();
            $table->char('empreinte', 64);
            $table->jsonb('sommaire');
            $table->text('signature');
            $table->timestamp('creee_le');
            $table->timestamp('a_effacer_le')->index();
        });

        DB::statement("alter table archives add constraint archives_reference_hexadecimale check (reference ~ '^[0-9a-f]{32}$')");
        DB::statement("alter table archives add constraint archives_empreinte_hexadecimale check (empreinte ~ '^[0-9a-f]{64}$')");
        DB::statement('alter table archives add constraint archives_gardee_apres_sa_creation check (a_effacer_le > creee_le)');
    }

    public function down(): void
    {
        Schema::dropIfExists('archives');
    }
};
