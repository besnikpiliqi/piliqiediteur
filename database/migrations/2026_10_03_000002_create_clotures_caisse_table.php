<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clotures_caisse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('numero', 40)->nullable();
            // La journée comptable clôturée, pas l'instant où le bouton a
            // été cliqué (voir cloturee_a) : une clôture peut être faite
            // le lendemain matin pour la veille (clôture manuelle en
            // retard, ou clôture automatique planifiée à minuit).
            $table->date('date_cloture');
            $table->unsignedInteger('nombre_ventes')->default(0);
            // Avoirs à des particuliers émis ce jour-là : les totaux
            // ci-dessous sont les ventes MOINS ces avoirs (voir
            // CloturerCaisseAction).
            $table->unsignedInteger('nombre_avoirs')->default(0);
            $table->decimal('total_ht', 12, 2)->default(0);
            $table->decimal('total_tva', 12, 2)->default(0);
            $table->decimal('total_ttc', 12, 2)->default(0);
            $table->timestamp('cloturee_a')->nullable();
            // Soumission d'e-reporting périodique qui a déjà inclus cette
            // clôture, pour ne jamais l'agréger deux fois (voir
            // GenererSoumissionEReportingAction). nullOnDelete : la
            // clôture, document fiscal, reste intacte quoi qu'il arrive à
            // la soumission.
            $table->foreignId('soumission_ereporting_id')->nullable()->constrained('soumissions_ereporting')->nullOnDelete();
            $table->timestamps();
            // PAS de softDeletes() ici, volontairement : une clôture de
            // caisse est un document fiscal au même titre qu'une facture
            // (obligation de conservation pour l'e-reporting).

            // Une seule clôture par jour et par magasin : la même journée
            // ne peut jamais être agrégée deux fois (voir
            // CloturerCaisseAction).
            $table->unique(['magasin_id', 'date_cloture']);
            $table->unique(['magasin_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clotures_caisse');
    }
};
