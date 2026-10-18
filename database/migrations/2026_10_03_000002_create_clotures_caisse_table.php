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
            // Le nom de cette personne au moment de l'opération (voir
            // FigeLeNomDeSesAuteurs) : l'historique ne le relit jamais sur
            // son compte.
            $table->string('cree_par_nom')->nullable();
            $table->string('numero', 40)->nullable();
            // La journée comptable clôturée, pas l'instant où le bouton a
            // été cliqué (voir cloturee_a) : une clôture peut être faite
            // le lendemain matin pour la veille (clôture manuelle en
            // retard, ou clôture automatique planifiée à minuit).
            $table->date('date_cloture');
            $table->unsignedInteger('nombre_ventes')->default(0);
            // Avoirs sur des tickets émis ce jour-là : les trois totaux
            // qui suivent, net des ventes aux particuliers et base de
            // l'e-reporting, sont les ventes MOINS ces avoirs (voir
            // CloturerCaisseAction).
            $table->unsignedInteger('nombre_avoirs')->default(0);
            $table->decimal('total_ht', 12, 2)->default(0);
            $table->decimal('total_tva', 12, 2)->default(0);
            $table->decimal('total_ttc', 12, 2)->default(0);
            // Le récapitulatif de la journée, écrit une seule fois à la
            // clôture, en centimes entiers (voir RecapitulatifDeCloture) :
            // ventes et avoirs aux particuliers, factures et avoirs aux
            // professionnels, chacun avec son nombre et ses montants par
            // taux, encaissements par mode, crédit d'avoir imputé et
            // grands totaux perpétuels. Un texte JSON, jamais une colonne
            // jsonb, qui réordonnerait ses clés : l'étape des clôtures
            // signées le signera tel quel.
            $table->json('recapitulatif');
            // Les dernières séquences des chaînes des ventes et des
            // encaissements du registre fiscal que la clôture a comptées
            // (null tant qu'aucune n'en compte) : la suivante part de là,
            // et chaque document ou paiement compte dans une seule clôture
            // (voir RecapitulatifDeCloture).
            $table->unsignedBigInteger('derniere_sequence_vente')->nullable();
            $table->unsignedBigInteger('derniere_sequence_encaissement')->nullable();
            $table->timestamp('cloturee_a')->nullable();
            $table->timestamps();
            // PAS de softDeletes() ici, volontairement : une clôture de
            // caisse est un document fiscal au même titre qu'une facture
            // (obligation de conservation), et l'e-reporting des ventes aux
            // particuliers de sa journée se lit sur elle (voir
            // EReportingDesParticuliers).

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
