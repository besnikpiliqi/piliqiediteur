<?php

use App\Domain\Demonstration\MarqueDeLaBase;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La marque de la base (lot DEMO-COPIE, A.5) : une table d'une seule
 * ligne, site_de_la_base, qui dit quel site a créé la base, « reel » sur le
 * site réel, « demonstration » dans une base modèle du site de
 * démonstration et donc dans chacune de ses copies ; la base refuse toute
 * modification, toute suppression et toute seconde ligne (voir
 * MarqueDeLaBase).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_de_la_base', function (Blueprint $table) {
            $table->text('site');
            $table->boolean('une_seule_ligne')->default(true)->unique();
        });

        DB::statement("alter table site_de_la_base add constraint site_de_la_base_site_connu check (site in ('reel', 'demonstration'))");
        DB::statement('alter table site_de_la_base add constraint site_de_la_base_une_seule_ligne check (une_seule_ligne)');
        MarqueDeLaBase::poser();
    }

    public function down(): void
    {
        Schema::dropIfExists('site_de_la_base');
        MarqueDeLaBase::retirerLaGarde();
    }
};
