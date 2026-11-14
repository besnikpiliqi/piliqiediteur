<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transmissions_pdp', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            // Polymorphique : une facture aujourd'hui (voir
            // Facture::transmissionsPdp()), et à l'étape 2 l'envoi des
            // ventes aux particuliers d'une clôture journalière -- même
            // mécanique de suivi et d'historique pour tous.
            $table->nullableMorphs('transmissible');
            $table->string('direction', 10); // outbound, inbound
            // Le nom du pilote de la plateforme agréée (voir
            // FournisseursExternesServiceProvider::pdpActive()).
            $table->string('prestataire_pdp', 60);
            // La référence sous laquelle la facture est déposée, tirée à la
            // création de la transmission (voir TransmissionPdp) et gardée
            // à chaque reprise après une panne : la plateforme retrouve
            // par elle un dépôt déjà reçu, et la même facture n'est jamais
            // déposée deux fois (voir SubmitFactureToPdpJob).
            $table->uuid('reference_depot')->unique();
            $table->string('transmission_externe_id')->nullable();
            $table->string('format', 10)->nullable(); // facturx (voir FakePdpGateway)
            $table->string('statut', 20)->default('soumise'); // voir StatutTransmissionPdp
            $table->string('chemin_fichier_archive')->nullable();
            $table->string('code_erreur', 60)->nullable();
            $table->text('message_erreur')->nullable();
            $table->timestamp('soumise_le')->nullable();
            $table->timestamp('statut_maj_le')->nullable();
            $table->timestamps();

            // Nom explicite : celui que Laravel dériverait dépasse les 63
            // caractères d'un identifiant PostgreSQL.
            $table->unique(['prestataire_pdp', 'transmission_externe_id'], 'transmissions_pdp_externe_unique');
            $table->index(['magasin_id', 'statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transmissions_pdp');
    }
};
