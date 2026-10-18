<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sequences_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            // Une valeur de TypeDocument : devis, facture, ticket_caisse,
            // avoir, facture_achat, avoir_fournisseur,
            // inventaire_ouverture, cloture_caisse, produit.
            $table->string('type_document', 40);
            // clé de période : "" (pas de reset), "2026" (reset annuel), etc.
            $table->string('period_key', 20)->default('');
            $table->string('prefixe', 30)->default('');
            $table->unsignedBigInteger('prochain_numero')->default(1);
            $table->unsignedTinyInteger('rembourrage')->default(6);
            $table->timestamps();

            $table->unique(['magasin_id', 'type_document', 'period_key'], 'sequences_documents_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sequences_documents');
    }
};
