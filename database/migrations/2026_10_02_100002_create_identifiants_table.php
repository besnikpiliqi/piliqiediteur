<?php

use App\Domain\Tenancy\Support\GardesDesIdentifiants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'identifiant de connexion de chaque compte (voir Identifiant et
 * GenerateurDIdentifiants) : 8 chiffres tirés au hasard, le premier de 1 à
 * 9, uniques dans tout le logiciel, jamais modifiés ni redonnés, même après
 * le retrait du compte. Un compte n'en a qu'un, pour toujours, même quand
 * il change de rôle.
 *
 * Le magasin de rattachement est celui pour lequel un compte de membre a
 * été créé : nul pour un compte né propriétaire, administrateur ou
 * expert-comptable, il ne s'écrit qu'une fois, de nul à une valeur, quand
 * un tel compte devient membre (voir ChangerLeRoleDeSocieteAction). Un
 * compte de membre ne change donc jamais de magasin, et une affectation de
 * membre n'existe que dans ce magasin (voir GardesDesDroits). Le magasin de
 * démonstration n'est accepté qu'à la création d'un compte de membre (un
 * compte d'entraînement), jamais ensuite.
 *
 * Le jeton de création est celui de l'écran qui a créé le compte, unique
 * dans la société : une demande de création répétée rend le compte déjà
 * créé (voir CreationDesComptes) ; son empreinte est celle de la demande
 * (rôle, magasin, nom, autorisations), sans aucun secret. Nuls pour le
 * propriétaire inscrit par son lien.
 *
 * La base refuse toute suppression, tout vidage et toute autre
 * modification (voir GardesDesIdentifiants).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identifiants', function (Blueprint $table) {
            $table->id();
            $table->char('identifiant', 8)->unique();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->foreignId('user_id')->unique();
            $table->foreignId('magasin_id')->nullable();
            $table->uuid('jeton_de_creation')->nullable();
            $table->char('empreinte_de_creation', 64)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['societe_id', 'jeton_de_creation']);
            $table->foreign(['societe_id', 'user_id'])->references(['societe_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['societe_id', 'magasin_id'])->references(['societe_id', 'id'])->on('magasins')->restrictOnDelete();
        });

        GardesDesIdentifiants::poser();
    }

    public function down(): void
    {
        Schema::dropIfExists('identifiants');
        GardesDesIdentifiants::retirer();
    }
};
