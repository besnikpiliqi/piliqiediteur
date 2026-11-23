<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La boîte d'envoi des messages du site réel vers le site de démonstration
 * (lot DEMO-COPIE, voir MessageVersLaDemonstration et
 * EnvoyerLesMessagesALaDemonstration) : chaque geste du propriétaire y
 * dépose son message dans la transaction qui le fait, et le travail les
 * signe et les envoie ensuite, dans l'ordre de leur démonstration.
 *
 * Le contenu est gardé sans signature, sans nonce ni instant d'émission,
 * qui se tirent à chaque envoi : seulement l'action, la référence et, selon
 * l'action, le type, le fait que la société soit validée, l'empreinte du
 * lien, sa génération et la fin. Jamais le lien, jamais rien d'autre de la
 * société. La démonstration est une clé composée (societe_id,
 * demonstration_id) vers les démonstrations de la même société.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages_vers_la_demonstration', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->foreignId('demonstration_id');
            $table->string('quoi', 30);
            $table->jsonb('contenu');
            $table->timestamp('cree_le');
            $table->timestamp('envoye_le')->nullable();
            $table->timestamp('accuse_le')->nullable();
            $table->unsignedSmallInteger('code_de_la_reponse')->nullable();
            $table->unsignedInteger('tentatives')->default(0);
            $table->timestamp('prochain_essai_le')->nullable();
            $table->string('derniere_erreur', 255)->nullable();

            $table->index(['demonstration_id', 'id']);
            $table->index(['accuse_le', 'prochain_essai_le']);
            $table->foreign(['societe_id', 'demonstration_id'])->references(['societe_id', 'id'])->on('demonstrations')->restrictOnDelete();
        });

        DB::statement("alter table messages_vers_la_demonstration add constraint messages_vers_la_demonstration_quoi_connu check (quoi in ('creer', 'renouveler_le_lien', 'recommencer', 'supprimer', 'etat'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('messages_vers_la_demonstration');
    }
};
