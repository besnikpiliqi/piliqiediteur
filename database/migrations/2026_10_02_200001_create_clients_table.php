<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            $table->string('nom', 150);
            $table->string('type_client', 20)->default('particulier');
            $table->string('siret', 14)->nullable();
            $table->string('numero_tva', 20)->nullable();
            $table->string('adresse')->nullable();
            $table->string('complement_adresse')->nullable();
            $table->string('code_postal', 10)->nullable();
            $table->string('ville', 100)->nullable();
            $table->string('pays', 2)->default('FR');
            $table->string('telephone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->unsignedSmallInteger('delai_paiement_jours')->nullable();
            $table->string('mode_paiement_prefere', 30)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('actif')->default(true);
            // Vrai pour la seule fiche du client comptoir du magasin (voir
            // CreerMagasinAction), NULL pour toutes les autres : un index
            // unique ignore les NULL (MySQL, PostgreSQL, SQLite), il
            // n'admet donc qu'un client comptoir par magasin.
            $table->boolean('est_comptoir')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['magasin_id', 'nom']);
            $table->index(['magasin_id', 'type_client']);
            $table->unique(['magasin_id', 'est_comptoir']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
