<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les e-mails qui seraient partis d'une copie (lot DEMO-COPIE, C.9) :
 * aucun e-mail ne part du site de démonstration ; son transport
 * « demonstration » (voir TransportDeLaDemonstration) écrit ici chaque
 * message que le vrai logiciel aurait envoyé, après toutes ses règles
 * d'envoi, avec la société d'où il part, le compte qui l'a demandé quand
 * il y en a un, l'expéditeur, les destinataires, l'objet, le HTML, le
 * texte et les pièces jointes (nom, type, taille et contenu en base64 ;
 * une pièce de plus de 10 Mo est gardée sans contenu). Cette table
 * n'existe que dans les bases du site de démonstration, jamais dans la
 * base réelle, et disparaît avec la copie ; elle n'entre pas dans
 * l'archive de sa caisse. Le compte est une clé composée (societe_id,
 * user_id) vers les comptes de la même société.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courriels_de_la_demonstration', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->foreignId('user_id')->nullable();
            $table->string('expediteur', 320);
            $table->text('destinataires');
            $table->text('copies')->nullable();
            $table->text('objet');
            $table->text('html')->nullable();
            $table->text('texte')->nullable();
            $table->jsonb('pieces_jointes');
            $table->timestamp('envoye_le');
            $table->index(['societe_id', 'envoye_le']);
            $table->foreign(['societe_id', 'user_id'])->references(['societe_id', 'id'])->on('users')->restrictOnDelete();
        });

        DB::statement("alter table courriels_de_la_demonstration add constraint courriels_de_la_demonstration_pieces_en_liste check (jsonb_typeof(pieces_jointes) = 'array')");
    }

    public function down(): void
    {
        Schema::dropIfExists('courriels_de_la_demonstration');
    }
};
