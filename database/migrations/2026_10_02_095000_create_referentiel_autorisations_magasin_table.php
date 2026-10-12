<?php

use App\Domain\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le référentiel des autorisations de magasin : une ligne par autorisation
 * de PermissionRegistry, que cette migration seule inscrit, et que
 * l'application n'écrit jamais. Chaque case d'une affectation le référence
 * (voir autorisations_affectations) : la base refuse donc une autorisation
 * inconnue, même écrite sans passer par l'application, et une chaîne qui
 * deviendrait un jour une autorisation n'ouvre jamais d'ancienne case. Une
 * autorisation qui porte encore des cases ne s'en retire pas : aucun droit
 * ne disparaît sans être inscrit au journal des événements de chaque
 * magasin touché (voir JournalDesDroits).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referentiel_autorisations_magasin', function (Blueprint $table) {
            $table->string('cle', 60)->primary();
        });

        DB::table('referentiel_autorisations_magasin')->insert(array_map(
            static fn (string $cle): array => ['cle' => $cle],
            PermissionRegistry::toutes(),
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('referentiel_autorisations_magasin');
    }
};
