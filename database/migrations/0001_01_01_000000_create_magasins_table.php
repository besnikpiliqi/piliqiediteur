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
            // Identité légale imprimée sur les devis, factures et avoirs
            // (voir IdentiteMagasin et documents/pdf/en-tete.blade.php).
            $table->string('raison_sociale', 200)->nullable();
            $table->string('forme_juridique', 100)->nullable();
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
            // Actif, suspendu ou fermé (voir StatutMagasin) : suspendu ou
            // fermé, le magasin passe en lecture seule.
            $table->string('statut', 30)->default('active');
            // Nullable : tant que le propriétaire n'a pas configuré son
            // régime (voir ParametresFiscaux), la soumission périodique
            // d'e-reporting (voir SoumettreEReportingPeriodique) ignore ce
            // magasin.
            $table->string('regime_tva', 30)->nullable();
            // Option pour les débits (CGI art. 269, 2-c) : ne concerne que
            // les prestations de services, champ du flux d'e-reporting
            // (voir CalculateurPeriodeEReporting).
            $table->boolean('option_debits_tva')->default(false);
            // Barème de prix de vente (voir BaremePrixDeVente) : chaque
            // magasin démarre avec l'exemple du logiciel, désactivé (voir
            // Magasin::booted()), qu'il active et règle s'il le veut.
            // Désactivé, il garde son barème pour le jour où il le
            // réactive.
            $table->boolean('prix_vente_automatique')->default(false);
            $table->json('bareme_prix_vente');
            // Relances des factures impayées (voir RelancerFacturesImpayees) :
            // désactivées au départ ; les délais, en jours après l'échéance,
            // démarrent avec l'exemple du logiciel (voir Magasin::booted())
            // et restent gardés quand le magasin les désactive.
            $table->boolean('relances_automatiques')->default(false);
            $table->json('delais_relance');
            $table->timestamps();
            $table->softDeletes();

            // Un SIRET ne sert jamais à un autre magasin, magasins supprimés
            // compris : un magasin ne s'efface jamais de cette table (voir
            // Magasin::booted()), ses documents restent conservés et son
            // établissement reste le sien.
            $table->unique('siret');
            $table->index(['statut']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('magasins');
    }
};
