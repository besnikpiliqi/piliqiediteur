<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('evenements_transmission_pdp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transmission_pdp_id')->constrained('transmissions_pdp')->cascadeOnDelete();
            $table->string('statut', 20);
            $table->json('charge_utile')->nullable();
            // Append-only : pas d'updated_at, un événement ne se corrige pas.
            $table->timestamp('survenu_le');

            // Nom explicite : celui que Laravel dériverait dépasse les 63
            // caractères d'un identifiant PostgreSQL.
            $table->index(['transmission_pdp_id', 'survenu_le'], 'evenements_transmission_pdp_survenu_le_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evenements_transmission_pdp');
    }
};
