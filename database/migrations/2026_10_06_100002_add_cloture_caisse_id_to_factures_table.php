<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('factures', function (Blueprint $table) {
            // Rattache une facture à un client particulier à la clôture
            // de caisse qui l'a agrégée pour l'e-reporting (voir
            // CloturerCaisseAction) -- jamais renseigné pour une facture
            // à un client professionnel, qui relève de l'e-invoicing
            // individuelle (voir Facture::relevantDeLeInvoicing).
            $table->foreignId('cloture_caisse_id')->nullable()->after('devis_id')
                ->constrained('clotures_caisse')->nullOnDelete();

            $table->index(['magasin_id', 'cloture_caisse_id']);
        });
    }

    public function down(): void
    {
        Schema::table('factures', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cloture_caisse_id');
        });
    }
};
