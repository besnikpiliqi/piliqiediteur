<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le registre des SIRET réservés (voir SiretMagasin et
 * SiretsDesMagasins) : un SIRET n'appartient qu'à un seul magasin de tout
 * le logiciel, dès que sa société est validée, et ne sert jamais à un
 * autre, même après un changement de SIRET (retire_le), sauf libération
 * par la procédure de l'éditeur (libere_le). Une ligne ne se corrige
 * jamais et ne s'efface pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sirets_magasins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->unsignedBigInteger('magasin_id');
            $table->string('siret', 14);
            $table->timestamp('reserve_le');
            // N'est plus le SIRET actuel du magasin : il reste réservé.
            $table->timestamp('retire_le')->nullable();
            // Libéré par la procédure de l'éditeur.
            $table->timestamp('libere_le')->nullable();
            $table->string('motif_liberation', 200)->nullable();
            $table->timestamps();

            $table->foreign(['societe_id', 'magasin_id'])->references(['societe_id', 'id'])->on('magasins')->restrictOnDelete();
            $table->index('magasin_id');
        });

        DB::statement('CREATE UNIQUE INDEX sirets_magasins_siret_reserve ON sirets_magasins (siret) WHERE libere_le IS NULL');
        DB::statement('CREATE UNIQUE INDEX sirets_magasins_un_actuel ON sirets_magasins (magasin_id) WHERE retire_le IS NULL AND libere_le IS NULL');

        DeclencheursDeProtection::sansSuppression('sirets_magasins');
    }

    public function down(): void
    {
        Schema::dropIfExists('sirets_magasins');
        DeclencheursDeProtection::retirer('sirets_magasins');
    }
};
