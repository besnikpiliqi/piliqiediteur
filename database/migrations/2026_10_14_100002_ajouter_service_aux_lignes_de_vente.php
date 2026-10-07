<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une ligne de vente est un produit, un service du catalogue (voir
 * Service), ou une ligne libre -- jamais un produit et un service à la
 * fois (voir ConstructeurLigneDocument). Même règle de suppression que
 * produit_id : la description reste figée sur la ligne.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->tables() as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('service_id')->nullable()->after('produit_id')->constrained('services')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables() as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('service_id');
            });
        }
    }

    /**
     * @return list<string>
     */
    private function tables(): array
    {
        return ['lignes_devis', 'lignes_facture'];
    }
};
