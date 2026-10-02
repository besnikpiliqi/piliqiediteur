<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('magasins', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('nom', 150);
            $table->string('raison_sociale', 200)->nullable();
            $table->string('siren', 9)->nullable();
            $table->string('siret', 14)->nullable();
            $table->string('numero_tva', 20)->nullable();
            $table->string('adresse')->nullable();
            $table->string('complement_adresse')->nullable();
            $table->string('code_postal', 10)->nullable();
            $table->string('ville', 100)->nullable();
            $table->string('pays', 2)->default('FR');
            $table->string('email', 150)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->string('devise', 3)->default('EUR');
            $table->string('locale', 5)->default('fr');
            $table->string('statut', 30)->default('pending_verification');
            $table->time('heure_cloture_automatique')->default('00:00:00');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('siret');
            $table->index(['statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magasins');
    }
};
