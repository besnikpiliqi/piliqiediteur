<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('magasins', function (Blueprint $table) {
            // Nullable : tant que le propriétaire n'a pas configuré son
            // régime (voir ParametresFiscaux), la soumission périodique
            // d'e-reporting (voir SoumettreEReportingPeriodique) ignore
            // simplement ce magasin -- même logique de dégradation douce
            // que l'absence de mandat PDP.
            $table->string('regime_tva', 30)->nullable()->after('statut');
            // Option pour les débits (CGI art. 269, 2-c) : ne concerne que
            // les prestations de services normalement soumises à la TVA
            // sur les encaissements -- un champ obligatoire du flux
            // d'e-reporting (voir CalculateurPeriodeEReporting).
            $table->boolean('option_debits_tva')->default(false)->after('regime_tva');
        });
    }

    public function down(): void
    {
        Schema::table('magasins', function (Blueprint $table) {
            $table->dropColumn(['regime_tva', 'option_debits_tva']);
        });
    }
};
