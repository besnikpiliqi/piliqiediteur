<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les demandes de transmission de la propriété d'une société (voir
 * TransmissionPropriete et DemanderLaTransmissionDeLaProprieteAction) : le
 * propriétaire la demande pour un administrateur, un lien part à l'adresse
 * que cet administrateur a renseignée lui-même dans son profil, valable 24
 * heures, et la propriété ne passe que quand il le suit, connecté à son
 * compte (voir AccepterLaTransmissionDeLaProprieteAction). Jusque-là rien
 * ne change et le propriétaire peut annuler.
 *
 * Seule l'empreinte SHA-256 du jeton du lien est gardée, jamais le jeton ;
 * « Renvoyer le lien » la remplace, et l'ancien lien ne sert plus.
 * L'adresse est celle de l'administrateur au moment de la demande,
 * normalisée : l'acceptation exige qu'elle n'ait pas changé. Les clés
 * étrangères composites (societe_id, user_id) gardent la demande dans la
 * société de ses deux comptes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transmissions_propriete', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->foreignId('demandee_par');
            $table->foreignId('destinataire_id');
            $table->string('adresse');
            $table->char('jeton_hache', 64)->unique();
            $table->timestamp('expire_le');
            $table->timestamp('annulee_le')->nullable();
            $table->timestamp('acceptee_le')->nullable();
            $table->timestamps();

            $table->index(['societe_id', 'destinataire_id']);
            $table->foreign(['societe_id', 'demandee_par'])->references(['societe_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['societe_id', 'destinataire_id'])->references(['societe_id', 'id'])->on('users')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transmissions_propriete');
    }
};
