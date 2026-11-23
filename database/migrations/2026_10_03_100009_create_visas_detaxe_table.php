<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La date du visa d'un bordereau de détaxe (BVE) à la sortie de l'Union,
 * notée sur la fiche du ticket détaxé (décision de l'éditeur du
 * 01/11/2026, Q-AF13, voir NoterLeVisaDuBordereauAction) : jusque-là, le
 * ticket attend son visa dans l'« À faire » du magasin, et sa TVA ne se
 * régularise qu'après. Un seul visa par bordereau, celui qui fait foi
 * quand il a été noté ; on n'y fait qu'ajouter : la base refuse toute
 * modification, toute suppression et tout vidage (voir
 * DeclencheursDeProtection), et les clés étrangères toute suppression d'un
 * magasin, d'un bordereau ou d'un membre qu'un visa désigne. Le bordereau
 * est désigné dans le magasin du visa (clé composée).
 *
 * note_par : le membre qui a noté la date du visa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visas_detaxe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->unsignedBigInteger('bordereau_detaxe_id');
            $table->date('date_visa');
            $table->foreignId('note_par')->nullable()->constrained('users')->restrictOnDelete();
            // Le nom de cette personne au moment de l'opération (voir
            // FigeLeNomDeSesAuteurs) : l'historique ne le relit jamais sur
            // son compte.
            $table->string('note_par_nom')->nullable();
            $table->timestamp('created_at');

            $table->unique(['magasin_id', 'bordereau_detaxe_id']);

            $table->foreign(['magasin_id', 'bordereau_detaxe_id'])->references(['magasin_id', 'id'])->on('bordereaux_detaxe')->restrictOnDelete();
        });

        DeclencheursDeProtection::ajoutSeul('visas_detaxe');
    }

    public function down(): void
    {
        Schema::dropIfExists('visas_detaxe');
        DeclencheursDeProtection::retirer('visas_detaxe');
    }
};
