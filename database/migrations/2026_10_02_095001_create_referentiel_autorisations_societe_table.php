<?php

use App\Domain\Authorization\AutorisationsSociete;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le référentiel des autorisations de société : une ligne par autorisation
 * d'AutorisationsSociete, que cette migration seule inscrit, et que
 * l'application n'écrit jamais. Chaque autorisation de société d'un
 * administrateur le référence (voir autorisations_societe) : la base
 * refuse une autorisation inconnue, comme une autorisation de magasin
 * cochée pour la société, et l'inverse. Une autorisation qui est encore
 * cochée pour quelqu'un ne s'en retire pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referentiel_autorisations_societe', function (Blueprint $table) {
            $table->string('cle', 60)->primary();
        });

        DB::table('referentiel_autorisations_societe')->insert(array_map(
            static fn (string $cle): array => ['cle' => $cle],
            AutorisationsSociete::toutes(),
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('referentiel_autorisations_societe');
    }
};
