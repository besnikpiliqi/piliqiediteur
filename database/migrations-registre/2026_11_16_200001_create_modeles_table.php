<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les bases modèles du site de démonstration (lot DEMO-COPIE, C.1 et C.2) :
 * une par type d'activité en service, refaite chaque nuit par
 * demonstration:construire-les-modeles, l'ancienne gardée « précédente »
 * jusqu'à la construction réussie suivante. Le registre pointe le modèle en
 * service : jamais une base renommée. Le mot de passe de démonstration est
 * fictif, propre au modèle, le même pour chacun de ses comptes, et le
 * bandeau de la copie l'affiche (choix 29) : il n'ouvre rien hors de la
 * copie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modeles', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('nom_de_la_base', 63)->unique();
            $table->timestamp('commence_le');
            $table->timestamp('construit_le')->nullable();
            $table->string('version_du_code', 40);
            $table->char('empreinte_du_schema', 64)->nullable();
            $table->string('mot_de_passe', 64);
            $table->string('etat', 20);
            $table->text('cause_de_l_echec')->nullable();
            $table->bigInteger('taille')->nullable();
            $table->unsignedInteger('duree_en_secondes')->nullable();
        });

        DB::statement("alter table modeles add constraint modeles_type_connu check (type in ('commerce', 'services', 'cabinet'))");
        DB::statement("alter table modeles add constraint modeles_etat_connu check (etat in ('en_construction', 'en_service', 'precedent', 'en_echec', 'supprime'))");
        DB::statement("alter table modeles add constraint modeles_nom_du_motif check (nom_de_la_base ~ '^demo_travail_[a-z]+_[0-9]{12}$')");
        DB::statement("alter table modeles add constraint modeles_construit_quand_en_service check (etat not in ('en_service', 'precedent') or (construit_le is not null and empreinte_du_schema is not null))");
        DB::statement("create unique index modeles_un_seul_en_service on modeles (type) where etat = 'en_service'");
        DB::statement("create unique index modeles_un_seul_precedent on modeles (type) where etat = 'precedent'");
    }

    public function down(): void
    {
        Schema::dropIfExists('modeles');
    }
};
