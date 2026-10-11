<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les membres de l'équipe d'un magasin (responsables, vendeurs,
 * comptables, observateurs), avec leur rôle et leur statut dans ce
 * magasin (voir MagasinUser), créés par le propriétaire de la société ou
 * par un membre qui gère l'équipe (voir CreerMembreMagasinAction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('magasin_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 30);
            $table->string('statut', 20);
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            // Un membre n'est rattaché qu'à un seul magasin : l'index refuse
            // un second rattachement. Le propriétaire de la société n'y
            // figure pas : il tient ses droits de son rôle de société
            // (voir User::estProprietaire()).
            $table->unique('user_id');
            $table->index(['magasin_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magasin_user');
    }
};
