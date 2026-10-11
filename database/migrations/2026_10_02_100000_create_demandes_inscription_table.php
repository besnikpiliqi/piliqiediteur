<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les demandes d'inscription d'une société, avant toute société (voir
 * DemandeInscription) : le lien envoyé à l'adresse du futur propriétaire
 * en porte le jeton, dont seule l'empreinte SHA-256 est gardée ; les
 * données saisies (SIREN, premier magasin, nom, mot de passe déjà haché,
 * résultat du contrôle Sirene) sont chiffrées. Le lien vaut 24 heures et
 * ne sert qu'une fois ; une demande s'efface 48 heures après son
 * expiration (voir app:purger-demandes-inscription). Table technique, sans
 * protection.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demandes_inscription', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->char('jeton_hache', 64)->unique();
            $table->text('donnees');
            $table->timestamp('expire_le');
            $table->timestamp('consommee_le')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('expire_le');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes_inscription');
    }
};
