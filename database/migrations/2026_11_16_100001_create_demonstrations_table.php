<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les démonstrations demandées par chaque société sur le site réel
 * (décision de l'éditeur du 15/11/2026 en soirée, lot DEMO-COPIE, voir
 * Demonstration) : ce que le site réel sait de la copie que le site de
 * démonstration tient pour la société, et rien de ce que les visiteurs y
 * font.
 *
 * La référence (128 bits, en hexadécimal) est l'adresse de la copie dans
 * le registre du site de démonstration : unique dans tout le logiciel
 * (unicité voulue, point 2 du protocole), jamais affichée, elle ne sort que
 * dans les messages signés et le billet d'entrée. Le lien n'est jamais
 * gardé : seulement son empreinte SHA-256, sa génération et l'instant où
 * il a été tiré. Le repère, six caractères sans ambiguïté, ne donne aucun
 * accès : il fait reconnaître sa copie.
 *
 * Une seule démonstration en cours par société (index unique partiel sur
 * les états demandee, en_preparation, en_attente_d_une_place et prete) ;
 * une démonstration en échec, terminée ou supprimée n'y compte pas. Le
 * compte qui l'a demandée est une clé composée (societe_id, demandee_par)
 * vers les comptes de la même société.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demonstrations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->char('reference', 32)->unique();
            $table->char('repere', 6);
            $table->string('type_d_activite', 20);
            $table->char('empreinte_du_lien', 64);
            $table->unsignedInteger('generation_du_lien');
            $table->timestamp('lien_tire_le');
            $table->unsignedInteger('generation_confirmee')->nullable();
            $table->timestamp('confirmee_le')->nullable();
            $table->timestamp('cree_le');
            $table->timestamp('fin_le');
            $table->string('etat', 30);
            $table->unsignedSmallInteger('essais_en_echec')->default(0);
            $table->timestamp('dernier_echec_le')->nullable();
            $table->string('cause_de_l_echec', 255)->nullable();
            $table->timestamp('recommencee_le')->nullable();
            $table->timestamp('terminee_le')->nullable();
            $table->string('cause_de_la_fin', 30)->nullable();
            $table->foreignId('demandee_par');
            $table->foreignId('lien_tire_par');

            $table->unique(['societe_id', 'id']);
            $table->index(['societe_id', 'etat']);
            $table->index(['etat', 'fin_le']);
            $table->foreign(['societe_id', 'demandee_par'])->references(['societe_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['societe_id', 'lien_tire_par'])->references(['societe_id', 'id'])->on('users')->restrictOnDelete();
        });

        DB::statement("alter table demonstrations add constraint demonstrations_reference_hexadecimale check (reference ~ '^[0-9a-f]{32}$')");
        DB::statement("alter table demonstrations add constraint demonstrations_repere_sans_ambiguite check (repere ~ '^[ACDEFGHJKMNPQRTUVWXY234679]{6}$')");
        DB::statement("alter table demonstrations add constraint demonstrations_empreinte_hexadecimale check (empreinte_du_lien ~ '^[0-9a-f]{64}$')");
        DB::statement("alter table demonstrations add constraint demonstrations_type_connu check (type_d_activite in ('commerce', 'services', 'cabinet'))");
        DB::statement("alter table demonstrations add constraint demonstrations_etat_connu check (etat in ('demandee', 'en_preparation', 'en_attente_d_une_place', 'prete', 'en_echec', 'terminee', 'supprimee'))");
        DB::statement("alter table demonstrations add constraint demonstrations_cause_de_la_fin_connue check (cause_de_la_fin is null or cause_de_la_fin in ('trente_jours', 'cloture', 'revocation', 'nouveau_proprietaire', 'echec'))");
        DB::statement('alter table demonstrations add constraint demonstrations_generation_positive check (generation_du_lien >= 1 and (generation_confirmee is null or generation_confirmee between 1 and generation_du_lien))');
        DB::statement('alter table demonstrations add constraint demonstrations_fin_apres_la_creation check (fin_le > cree_le)');
        DB::statement("alter table demonstrations add constraint demonstrations_fin_dite check ((etat in ('terminee', 'supprimee')) = (terminee_le is not null and cause_de_la_fin is not null))");
        DB::statement("create unique index demonstrations_une_seule_en_cours on demonstrations (societe_id) where etat in ('demandee', 'en_preparation', 'en_attente_d_une_place', 'prete')");
    }

    public function down(): void
    {
        Schema::dropIfExists('demonstrations');
    }
};
