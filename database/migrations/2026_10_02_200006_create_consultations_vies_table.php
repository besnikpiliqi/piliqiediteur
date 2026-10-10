<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les vérifications du n° de TVA des clients professionnels sur VIES, le
 * service de la Commission européenne (voir VerifierNumeroTvaAction) :
 * chacune est la preuve, datée, que ce n° était valide ou inconnu ce
 * jour-là, condition d'une livraison intracommunautaire ou d'une
 * prestation autoliquidée (art. 262 ter I et 259-1° du CGI). On n'y fait
 * donc qu'ajouter : la base refuse toute modification, toute suppression
 * et tout vidage (voir DeclencheursDeProtection), et les clés étrangères
 * toute suppression d'un magasin, d'un client ou d'un membre qu'une
 * vérification désigne.
 *
 * numero_tva est le n° vérifié, tel que la fiche l'avait alors : la
 * vérification reste celle de ce n°, même quand la fiche en change.
 * consultee_le est l'instant de la vérification, en UTC à la seconde
 * (voir HorodatageUtc) ; identifiant_consultation, le n° que VIES donne à
 * la consultation quand le magasin s'est identifié par son propre n° de
 * TVA ; reponse, la réponse de VIES telle qu'elle a été reçue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultations_vies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('numero_tva', 20);
            $table->boolean('valide');
            $table->string('nom')->nullable();
            $table->text('adresse')->nullable();
            $table->string('identifiant_consultation', 64)->nullable();
            $table->dateTime('consultee_le');
            $table->foreignId('consultee_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('reponse');

            $table->index(['magasin_id', 'client_id', 'consultee_le']);
        });

        DeclencheursDeProtection::ajoutSeul('consultations_vies');
    }

    public function down(): void
    {
        Schema::dropIfExists('consultations_vies');
        DeclencheursDeProtection::retirer('consultations_vies');
    }
};
