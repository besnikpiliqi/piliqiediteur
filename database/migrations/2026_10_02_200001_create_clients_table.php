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
            // Identifiants d'un professionnel, vides pour un particulier
            // (voir Client::booted()) : le SIREN, obligatoire pour un
            // professionnel établi en France, que donnent aussi les 9
            // premiers chiffres de son SIRET ; pour une entreprise établie
            // hors de France, son n° de TVA ou un autre identifiant (voir
            // ClientResource).
            $table->string('siren', 9)->nullable();
            $table->string('siret', 14)->nullable();
            $table->string('numero_tva', 20)->nullable();
            $table->boolean('etabli_en_france')->default(true);
            $table->string('identifiant_etranger', 50)->nullable();
            $table->string('adresse')->nullable();
            $table->string('complement_adresse')->nullable();
            $table->string('code_postal', 10)->nullable();
            $table->string('ville', 100)->nullable();
            $table->string('pays', 2)->default('FR');
            $table->string('telephone', 30)->nullable();
            $table->string('email', 150)->nullable();
            $table->unsignedSmallInteger('delai_paiement_jours')->nullable();
            // Une valeur de ModePaiement.
            $table->string('mode_paiement_prefere', 20)->nullable();
            $table->text('notes')->nullable();
            // Vrai pour la seule fiche du client comptoir du magasin (voir
            // CreerMagasinAction), NULL pour toutes les autres : un index
            // unique ignore les NULL (PostgreSQL comme SQLite), il
            // n'admet donc qu'un client comptoir par magasin.
            $table->boolean('est_comptoir')->nullable();
            $table->timestamps();
            // Un client ne se supprime pas : il s'archive (voir
            // ClientResource), ses documents le gardent, et il se restaure.
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
