<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Créée avant users : un utilisateur retient le magasin sur lequel il
 * travaille (users.current_magasin_id).
 */
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
            // Nullable : tant que le propriétaire n'a pas configuré son
            // régime (voir ParametresFiscaux), la soumission périodique
            // d'e-reporting (voir SoumettreEReportingPeriodique) ignore ce
            // magasin.
            $table->string('regime_tva', 30)->nullable();
            // Option pour les débits (CGI art. 269, 2-c) : ne concerne que
            // les prestations de services, champ du flux d'e-reporting
            // (voir CalculateurPeriodeEReporting).
            $table->boolean('option_debits_tva')->default(false);
            $table->time('heure_cloture_automatique')->default('00:00:00');
            // Barème de prix de vente (voir BaremePrixDeVente) : chaque
            // magasin démarre avec l'exemple du logiciel, désactivé (voir
            // Magasin::booted()), qu'il active et règle s'il le veut.
            // Désactivé, il garde son barème pour le jour où il le
            // réactive.
            $table->boolean('prix_vente_automatique')->default(false);
            $table->json('bareme_prix_vente')->nullable();
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
