<?php

use App\Domain\Authorization\PermissionRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le référentiel des autorisations de magasin : une ligne par autorisation
 * de PermissionRegistry, avec sa famille (celle des articles, voir
 * PermissionRegistry::articles(), ou la famille commune), que cette
 * migration seule inscrit, et que l'application n'écrit jamais. Chaque
 * case d'une affectation le référence (voir autorisations_affectations) :
 * la base refuse donc une autorisation inconnue, même écrite sans passer
 * par l'application, et une chaîne qui deviendrait un jour une
 * autorisation n'ouvre jamais d'ancienne case ; elle lit aussi la famille
 * de chaque case, pour refuser une case de la famille des articles dans
 * une société qui n'en a pas (voir GardesDesTypesDActivite). Une
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
            $table->string('famille', 20);
        });

        $this->limiterLesFamilles();

        DB::table('referentiel_autorisations_magasin')->insert(array_map(
            static fn (string $cle): array => [
                'cle' => $cle,
                'famille' => in_array($cle, PermissionRegistry::articles(), true) ? PermissionRegistry::FAMILLE_ARTICLES : PermissionRegistry::FAMILLE_COMMUNE,
            ],
            PermissionRegistry::toutes(),
        ));
    }

    /**
     * Une famille est celle des articles ou la famille commune : une
     * contrainte CHECK sous PostgreSQL ; SQLite n'en ajoute pas à une
     * table créée, et la même condition y est vérifiée par des
     * déclencheurs BEFORE INSERT et BEFORE UPDATE. L'une et les autres
     * disparaissent avec la table.
     */
    private function limiterLesFamilles(): void
    {
        $nom = 'referentiel_autorisations_magasin_famille_connue';
        $condition = "famille IN ('".PermissionRegistry::FAMILLE_COMMUNE."', '".PermissionRegistry::FAMILLE_ARTICLES."')";

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE referentiel_autorisations_magasin ADD CONSTRAINT {$nom} CHECK ({$condition})");

            return;
        }

        foreach (['INSERT', 'UPDATE'] as $operation) {
            DB::unprepared("CREATE TRIGGER {$nom}_".strtolower($operation)." BEFORE {$operation} ON referentiel_autorisations_magasin"
                ." WHEN NOT (NEW.{$condition})"
                ." BEGIN SELECT RAISE(ABORT, '{$nom} : famille inconnue'); END");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('referentiel_autorisations_magasin');
    }
};
