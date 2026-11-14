<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le lien secret par lequel le client répond à un devis envoyé par
 * e-mail (voir LienDuDevis) : un par envoi, créé avec lui (voir
 * EnvoyerDocumentAction). Seule l'empreinte SHA-256 de son jeton se garde,
 * écrite avec lui, jamais le jeton, que seuls la tâche d'envoi, chiffré,
 * et l'e-mail portent (voir EnvoyerDocumentJob).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liens_des_devis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->unsignedBigInteger('devis_id');
            $table->unsignedBigInteger('envoi_document_id');
            // L'empreinte du jeton que porte l'e-mail, unique dans tout le
            // logiciel : la page du lien la cherche avant qu'aucun magasin
            // ne soit ouvert.
            $table->char('jeton_hache', 64)->unique();
            // Le retrait du lien par le magasin (voir
            // RetirerLeLienDuDevisAction), avec le nom figé de qui l'a fait.
            $table->timestamp('retire_le')->nullable();
            $table->foreignId('retire_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('retire_par_nom')->nullable();
            $table->timestamps();

            $table->unique('envoi_document_id');
            $table->unique(['magasin_id', 'id']);
            $table->index(['magasin_id', 'devis_id']);
            $table->foreign(['magasin_id', 'devis_id'])->references(['magasin_id', 'id'])->on('devis')->restrictOnDelete();
            $table->foreign(['magasin_id', 'envoi_document_id'])->references(['magasin_id', 'id'])->on('envois_documents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('liens_des_devis');
    }
};
