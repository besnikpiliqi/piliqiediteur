<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les téléchargements des documents par période (décision de l'éditeur du
 * 03/11/2026, lot EX ; voir TelechargementDeDocuments) : chaque demande,
 * dans la société dont elle lit les documents, avec ce qui a été demandé
 * (magasins, période, types, origine des achats, format, documents
 * choisis), son état dans la file d'attente et, une fois prêt, son fichier
 * sur le disque privé « telechargements ». Le lien vaut sept jours, pour le
 * seul compte qui l'a demandé ; la demande et son fichier s'effacent
 * ensuite (voir app:effacer-telechargements-de-documents) ; le journal
 * d'audit de la société en garde la trace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telechargements_documents', function (Blueprint $table) {
            $table->id();
            // L'identifiant de son lien, tiré au hasard : jamais une
            // adresse devinable (voir TelechargerLesDocumentsController).
            $table->uuid('uuid')->unique();
            // La société dont il lit les documents : celle de la personne,
            // ou le client lié d'un cabinet.
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            // Le compte qui l'a demandé, seul à pouvoir le télécharger ; un
            // compte d'un cabinet appartient à une autre société.
            $table->foreignId('demande_par')->constrained('users')->restrictOnDelete();
            $table->string('demande_par_nom');
            // Les magasins, les types, les clés des documents choisis (voir
            // DocumentTrouve::cle()), en JSON.
            $table->json('magasins');
            $table->date('du');
            $table->date('au');
            $table->json('types');
            $table->string('origine', 20);
            $table->string('format', 10);
            $table->json('selection');
            $table->string('statut', 20);
            $table->unsignedInteger('nombre_documents')->nullable();
            $table->string('chemin')->nullable();
            $table->unsignedBigInteger('taille')->nullable();
            $table->string('motif_echec')->nullable();
            // L'instant de sa préparation, et celui où son lien cesse de
            // valoir (sept jours après la demande), en UTC.
            $table->dateTime('prepare_le')->nullable();
            $table->dateTime('expire_le');
            $table->timestamps();

            $table->index(['demande_par', 'created_at']);
            $table->index('expire_le');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telechargements_documents');
    }
};
