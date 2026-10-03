<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clotures_caisse', function (Blueprint $table) {
            // Marque une clôture comme déjà incluse dans une soumission
            // d'e-reporting périodique, pour ne jamais l'agréger deux fois
            // (voir GenererSoumissionEReportingAction). nullOnDelete : si
            // la soumission est un jour supprimée, la clôture elle-même
            // (document fiscal, jamais supprimable) doit rester intacte.
            $table->foreignId('soumission_ereporting_id')
                ->nullable()
                ->after('cloturee_a')
                ->constrained('soumissions_ereporting')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clotures_caisse', function (Blueprint $table) {
            $table->dropConstrainedForeignId('soumission_ereporting_id');
        });
    }
};
