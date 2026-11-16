<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les copies du site de démonstration (lot DEMO-COPIE, C.1) : une base
 * copiée d'un modèle par société qui l'a demandée sur le site réel. Rien ne
 * vient de la vraie société : seulement la référence que le site réel a
 * tirée (128 bits en hexadécimal), le repère, le type, si la société est
 * validée (part réservée du plafond, choix 7), la génération du lien et la
 * fin. Le nom de la base est tiré au hasard (ULID en minuscules) et ne dit
 * rien de la société. Une copie supprimée garde sa ligne 30 jours, pour
 * répondre « cette démonstration est terminée ».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('copies', function (Blueprint $table) {
            $table->id();
            $table->char('reference', 32)->unique();
            $table->char('repere', 6);
            $table->string('type', 20);
            $table->boolean('validee');
            $table->string('nom_de_la_base', 63)->nullable()->unique();
            $table->foreignId('modele_id')->nullable()->constrained('modeles')->restrictOnDelete();
            $table->timestamp('creee_le');
            $table->timestamp('prete_le')->nullable();
            $table->timestamp('fin');
            $table->unsignedInteger('generation_du_lien');
            $table->string('etat', 30);
            $table->unsignedSmallInteger('essais')->default(0);
            $table->timestamp('prochain_essai_le')->nullable();
            $table->text('cause_de_l_echec')->nullable();
            $table->string('version', 40)->nullable();
            $table->char('empreinte_du_schema', 64)->nullable();
            $table->bigInteger('taille_mesuree')->nullable();
            $table->timestamp('derniere_passe_des_taches_le')->nullable();
            $table->timestamp('derniere_verification_le')->nullable();
            $table->string('message', 255)->nullable();
            $table->string('motif_du_message', 30)->nullable();
            $table->timestamp('supprimee_le')->nullable();
            $table->index(['etat', 'fin']);
        });

        DB::statement("alter table copies add constraint copies_reference_hexadecimale check (reference ~ '^[0-9a-f]{32}$')");
        DB::statement("alter table copies add constraint copies_repere_sans_ambiguite check (repere ~ '^[ACDEFGHJKMNPQRTUVWXY234679]{6}$')");
        DB::statement("alter table copies add constraint copies_type_connu check (type in ('commerce', 'services', 'cabinet'))");
        DB::statement("alter table copies add constraint copies_nom_du_motif check (nom_de_la_base is null or nom_de_la_base ~ '^demo_copie_[a-z0-9]{26}$')");
        DB::statement("alter table copies add constraint copies_etat_connu check (etat in ('en_preparation', 'en_attente_d_une_place', 'prete', 'en_echec', 'a_refaire', 'en_migration', 'en_lecture_seule', 'en_archivage', 'en_suppression', 'supprimee', 'perdue'))");
        DB::statement("alter table copies add constraint copies_servie_avec_sa_base check (etat not in ('prete', 'en_lecture_seule') or (nom_de_la_base is not null and modele_id is not null and empreinte_du_schema is not null))");
        DB::statement("alter table copies add constraint copies_supprimee_datee check ((etat = 'supprimee') = (supprimee_le is not null))");
        DB::statement('alter table copies add constraint copies_fin_apres_la_creation check (fin > creee_le)');
        DB::statement('alter table copies add constraint copies_generation_positive check (generation_du_lien >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('copies');
    }
};
