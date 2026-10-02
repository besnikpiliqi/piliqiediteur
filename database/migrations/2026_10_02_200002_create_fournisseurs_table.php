<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fournisseurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            $table->string('nom', 150);
            // SIRET (établissement précis), pas SIREN : c'est l'identifiant
            // utilisé pour le routage e-invoicing/matching des factures
            // d'achat reçues automatiquement via le PDP (voir Fournisseur::
            // upsertDepuisFactureEntrante).
            $table->string('siret', 14)->nullable();
            $table->string('numero_tva', 20)->nullable();
            $table->string('adresse')->nullable();
            $table->string('complement_adresse')->nullable();
            $table->string('code_postal', 10)->nullable();
            $table->string('ville', 100)->nullable();
            $table->string('pays', 2)->default('FR');
            $table->string('telephone', 30)->nullable();
            $table->string('email', 150)->nullable();
            // manual : saisi à la main / pdp_auto : créé automatiquement à
            // réception d'une facture d'achat dont le SIRET émetteur était
            // inconnu -- utile pour signaler en UI "fournisseur à vérifier".
            $table->string('source', 20)->default('manual');
            $table->boolean('actif')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['magasin_id', 'siret']);
            $table->index(['magasin_id', 'nom']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fournisseurs');
    }
};
