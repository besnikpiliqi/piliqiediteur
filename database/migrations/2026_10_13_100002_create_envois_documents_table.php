<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historique des envois par e-mail d'un devis, d'une facture, d'un avoir
 * ou d'un reçu de paiement (voir EnvoyerDocumentAction) : chaque envoi et
 * chaque relance d'une facture impayée y laisse une ligne, qu'il ait
 * abouti ou non.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('envois_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            // Polymorphique : facture, devis, avoir ou reçu de paiement.
            $table->morphs('document');
            $table->string('destinataire', 254);
            $table->string('objet');
            $table->text('message')->nullable();
            $table->string('nature', 20); // envoi, relance
            // Une relance seulement : 1 pour la première, puis 2, 3...
            $table->unsignedSmallInteger('niveau_relance')->nullable();
            $table->string('statut', 20)->default('en_attente'); // en_attente, envoye, echec
            // Une explication pour le magasin, jamais le message technique
            // du serveur d'envoi (voir CauseDEchecDEnvoi).
            $table->string('erreur', 500)->nullable();
            // Les tentatives d'envoi déjà commencées, la cause traduite de
            // la dernière qui a échoué et l'heure de la suivante, que montre
            // la fiche tant que l'envoi attend (voir EnvoyerDocumentJob).
            $table->unsignedSmallInteger('tentatives')->default(0);
            $table->string('derniere_erreur')->nullable();
            $table->timestamp('prochaine_tentative_le')->nullable();
            $table->foreignId('envoye_par')->nullable()->constrained('users')->restrictOnDelete();
            // Le nom de cette personne au moment de l'opération (voir
            // FigeLeNomDeSesAuteurs) : l'historique ne le relit jamais sur
            // son compte.
            $table->string('envoye_par_nom')->nullable();
            $table->timestamp('envoye_le')->nullable();
            $table->timestamps();

            $table->index(['magasin_id', 'statut']);
            // La cible des clés composées des tables du magasin qui suivent
            // un envoi (voir liens_des_devis).
            $table->unique(['magasin_id', 'id']);
            // Jamais deux fois le même niveau de relance pour un document :
            // un envoi simple (niveau nul) peut, lui, se répéter. Nom
            // explicite : celui que Laravel dériverait dépasse les 63
            // caractères d'un identifiant PostgreSQL.
            $table->unique(['document_type', 'document_id', 'niveau_relance'], 'envois_documents_relance_unique');
        });

        DeclencheursDeProtection::auMoins('envois_documents', 'niveau_relance', 0);
        DeclencheursDeProtection::auMoins('envois_documents', 'tentatives', 0);
    }

    public function down(): void
    {
        Schema::dropIfExists('envois_documents');
    }
};
