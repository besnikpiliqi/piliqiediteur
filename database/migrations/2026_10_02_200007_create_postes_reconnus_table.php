<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les postes du magasin que le propriétaire ou un responsable a reconnus
 * (voir PosteReconnu et PostesReconnus) : un navigateur du magasin, qui
 * porte un témoin propre à ce poste. Tant que le mot de passe d'un compte
 * est encore son identifiant, il n'ouvre ce compte que depuis un poste
 * reconnu d'un magasin que ce compte ouvre (voir
 * FournisseurDesComptes::validateCredentials()) ; ailleurs, la personne
 * doit avoir choisi son mot de passe.
 *
 * Seule l'empreinte SHA-256 du jeton du témoin est gardée, jamais le
 * jeton. Un poste se retire à tout moment : son témoin ne sert plus. Qui
 * l'a reconnu et qui l'a retiré gardent leur nom tel qu'il était (voir
 * FigeLeNomDeSesAuteurs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postes_reconnus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->string('nom', 100);
            $table->char('jeton_hache', 64)->unique();
            $table->foreignId('reconnu_par')->constrained('users')->restrictOnDelete();
            $table->string('reconnu_par_nom');
            $table->foreignId('retire_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('retire_par_nom')->nullable();
            $table->timestamp('retire_le')->nullable();
            $table->timestamps();

            $table->index(['magasin_id', 'retire_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('postes_reconnus');
    }
};
