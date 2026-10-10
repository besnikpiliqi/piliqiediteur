<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avoirs', function (Blueprint $table) {
            $table->id();
            // restrictOnDelete partout : un avoir émis ne se réécrit ni ne
            // disparaît (voir ProtegeLesDonneesValidees), pas même par la
            // suppression d'une fiche qu'il référence.
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            // Un avoir appartient à un client, pas à une facture : il peut
            // regrouper des lignes de plusieurs factures du même client
            // (voir CreerAvoirAction). La facture d'origine de chaque ligne
            // est connue par lignes_avoir.ligne_facture_id.
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            // Le type du client des tickets ou des factures que l'avoir
            // corrige (voir CreerAvoirAction), qui ne se mélangent jamais :
            // particulier pour des tickets de caisse, professionnel pour
            // des factures.
            $table->string('type_client', 20);
            // La copie du client prise à l'émission de l'avoir sur le
            // document qu'il corrige, le plus récent s'il en corrige
            // plusieurs, et non sur la fiche du client (voir
            // Avoir::clientAFiger()) : mêmes colonnes que
            // factures.client_nom. Vides sur un brouillon.
            $table->string('client_nom', 150)->nullable();
            $table->string('client_adresse')->nullable();
            $table->string('client_complement_adresse')->nullable();
            $table->string('client_code_postal', 10)->nullable();
            $table->string('client_ville', 100)->nullable();
            $table->string('client_pays', 2)->nullable();
            $table->string('client_siren', 9)->nullable();
            $table->string('client_siret', 14)->nullable();
            $table->string('client_numero_tva', 20)->nullable();
            $table->string('client_identifiant_etranger', 50)->nullable();
            $table->boolean('client_etabli_en_france')->nullable();
            // Clôture de caisse qui a déduit cet avoir sur des tickets des
            // ventes de sa journée (voir CloturerCaisseAction) -- jamais
            // renseignée pour un avoir sur des factures, comme pour une
            // facture (voir factures.cloture_caisse_id). Seule colonne
            // encore écrite sur un avoir émis : l'étape des clôtures la
            // supprimera.
            $table->foreignId('cloture_caisse_id')->nullable()->constrained('clotures_caisse')->nullOnDelete();
            $table->foreignId('cree_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('numero', 40)->nullable();
            $table->string('statut', 20)->default('brouillon');
            // L'instant du registre fiscal à l'émission, en UTC à la seconde
            // (voir HorodateSonEmission) : dateTime, que MySQL ne convertit
            // jamais selon le fuseau de la connexion, contrairement à
            // timestamp.
            $table->dateTime('date_emission')->nullable();
            $table->string('devise', 3)->default('EUR');
            $table->text('motif')->nullable();
            $table->decimal('total_ht', 12, 2)->default(0);
            $table->decimal('total_tva', 12, 2)->default(0);
            $table->decimal('total_ttc', 12, 2)->default(0);
            $table->timestamps();
            // PAS de softDeletes() ici, volontairement : un avoir est, au
            // même titre qu'une facture, un document fiscal dont la loi
            // impose la conservation (voir AvoirPolicy).

            $table->unique(['magasin_id', 'numero']);
            $table->index(['magasin_id', 'client_id']);
            $table->index(['magasin_id', 'cloture_caisse_id']);
            $table->index(['magasin_id', 'type_client', 'date_emission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avoirs');
    }
};
