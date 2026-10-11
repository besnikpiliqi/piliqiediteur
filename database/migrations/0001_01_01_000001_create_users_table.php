<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un compte n'appartient qu'à une seule société (voir User) : son
 * propriétaire, un administrateur ou un membre (voir RoleSociete). Un
 * compte ne s'efface pas : retiré, il garde son adresse, qui ne sert
 * jamais à un autre compte (voir StatutCompte).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->string('role_societe', 20)->default('membre');
            $table->string('statut', 20)->default('actif');
            $table->string('name');
            // En minuscules, sans espaces autour (voir
            // User::normaliserEmail()) : unique dans tout le logiciel.
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();

            // La cible des clés étrangères composites (societe_id,
            // user_id) : une ligne n'y désigne jamais le compte d'une autre
            // société.
            $table->unique(['societe_id', 'id']);
            $table->index(['societe_id', 'role_societe']);
        });

        // Un seul propriétaire par société.
        DB::statement("CREATE UNIQUE INDEX users_un_proprietaire_par_societe ON users (societe_id) WHERE role_societe = 'proprietaire'");

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
