<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les nonces des messages signés et des billets d'entrée déjà reçus (lot
 * DEMO-COPIE, C.5 et C.7) : un message rejoué est refusé. Un message vit
 * 5 minutes au plus, un billet 60 secondes : les nonces s'effacent après
 * un jour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages_recus', function (Blueprint $table) {
            $table->char('nonce', 32)->primary();
            $table->string('audience', 20);
            $table->timestamp('recu_le')->index();
        });

        DB::statement("alter table messages_recus add constraint messages_recus_nonce_hexadecimal check (nonce ~ '^[0-9a-f]{32}$')");
        DB::statement("alter table messages_recus add constraint messages_recus_audience_connue check (audience in ('messages', 'entree'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_recus');
    }
};
