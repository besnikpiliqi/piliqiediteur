<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les demandes déjà traitées dans une société, hors de tout magasin (R3,
 * voir ActionsUniques::executerDansLaSociete()) : le jeton unique que porte
 * chaque geste sur les charges de la société (déposer, écrire les
 * chiffres, valider, renvoyer, corriger, supprimer), l'empreinte de son
 * contenu et son résultat. Une demande répétée avec le même jeton renvoie
 * ce résultat sans rien refaire. La personne qui l'a demandée peut être un
 * compte d'un cabinet lié, d'une autre société : sa clé étrangère vise le
 * compte seul. Table technique sans valeur fiscale : chaque ligne
 * s'efface 30 jours après son inscription, comme celles des magasins
 * (voir app:purger-actions-traitees).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actions_traitees_de_la_societe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->uuid('jeton');
            $table->string('action', 40);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->char('empreinte_demande', 64);
            $table->string('resultat_type', 40)->nullable();
            $table->unsignedBigInteger('resultat_id')->nullable();
            $table->timestamp('created_at');

            $table->unique(['societe_id', 'jeton']);
            $table->index(['societe_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actions_traitees_de_la_societe');
    }
};
