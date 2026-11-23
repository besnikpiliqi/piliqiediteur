<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les témoins des visites (lot DEMO-COPIE, C.1, C.4 et C.5) : seulement
 * l'empreinte SHA-256 de la valeur du témoin (256 bits tirés au hasard),
 * la génération du lien à sa création (un renouvellement retire tous les
 * témoins d'une génération plus ancienne, choix 10), sa fin, qui ne
 * dépasse jamais celle de la copie, le dernier compte choisi dans le
 * bandeau (la visite suivante le rouvre) et le message de la copie déjà
 * montré à ce témoin (chaque message ne se montre qu'une fois par
 * témoin, E.4). Au plus 50 témoins actifs par copie : au-delà, le plus ancien
 * est retiré.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temoins', function (Blueprint $table) {
            $table->id();
            $table->char('empreinte', 64)->unique();
            $table->foreignId('copie_id')->constrained('copies')->restrictOnDelete();
            $table->unsignedInteger('generation_du_lien');
            $table->string('entree', 10);
            $table->timestamp('cree_le');
            $table->timestamp('fin');
            $table->timestamp('vu_le')->nullable();
            $table->timestamp('retire_le')->nullable();
            $table->unsignedBigInteger('compte_choisi')->nullable();
            $table->text('message_vu')->nullable();
            $table->index(['copie_id', 'retire_le']);
        });

        DB::statement("alter table temoins add constraint temoins_empreinte_hexadecimale check (empreinte ~ '^[0-9a-f]{64}$')");
        DB::statement("alter table temoins add constraint temoins_entree_connue check (entree in ('lien', 'bouton'))");
        DB::statement('alter table temoins add constraint temoins_fin_apres_la_creation check (fin > cree_le)');
    }

    public function down(): void
    {
        Schema::dropIfExists('temoins');
    }
};
