<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factures', function (Blueprint $table) {
            $table->id();
            // restrictOnDelete partout : une facture émise ne se réécrit ni
            // ne disparaît (art. 286 I-3° bis du CGI, voir
            // ProtegeLesDonneesValidees), pas même par la suppression d'une
            // fiche qu'elle référence.
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->foreignId('devis_id')->nullable()->constrained('devis')->restrictOnDelete();
            // Le type du client (TypeClient), écrit à chaque enregistrement
            // du brouillon et contrôlé à l'émission (voir
            // EmettreFactureAction) : il choisit la série, ticket de caisse
            // ou facture, puis ne change plus.
            $table->string('type_client', 20);
            // La copie du client prise à l'émission (voir FigeLeClient) :
            // nom, adresse et identifiants tels qu'ils s'impriment et se
            // signent au registre fiscal, que la fiche du client change ou
            // non ensuite. Vides sur un brouillon, qui lit la fiche.
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
            // Clôture de caisse qui a agrégé ce ticket de caisse pour
            // l'e-reporting (voir CloturerCaisseAction) -- jamais renseignée
            // pour une facture à un professionnel (voir
            // Facture::scopeTickets()). Seule colonne, avec le statut, encore
            // écrite sur une facture émise : l'étape des clôtures la
            // supprimera.
            $table->foreignId('cloture_caisse_id')->nullable()->constrained('clotures_caisse')->nullOnDelete();
            // Qui a créé la facture : nécessaire pour restreindre la
            // visibilité d'un vendeur à ses propres ventes par défaut
            // (voir FacturePolicy et FACTURE_VIEW_TOUTES).
            $table->foreignId('cree_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('numero', 40)->nullable();
            $table->string('statut', 20)->default('brouillon');
            // L'instant du registre fiscal à l'émission, en UTC à la seconde
            // (voir HorodateSonEmission) : dateTime, que MySQL ne convertit
            // jamais selon le fuseau de la connexion, contrairement à
            // timestamp.
            $table->dateTime('date_emission')->nullable();
            $table->date('date_echeance')->nullable();
            $table->string('devise', 3)->default('EUR');
            $table->text('conditions_paiement')->nullable();
            $table->text('notes')->nullable();
            $table->decimal('total_ht', 12, 2)->default(0);
            $table->decimal('total_tva', 12, 2)->default(0);
            $table->decimal('total_ttc', 12, 2)->default(0);
            // Ni montant payé ni reste dû ne sont stockés : ils se
            // recalculent depuis les imputations de paiement et d'avoir de
            // la facture (voir Facture::montantPaye(),
            // montantCrediteParAvoir() et montantRestant()), qui ne se
            // modifient ni ne se suppriment.
            $table->timestamps();
            // PAS de softDeletes() ici, volontairement : une facture
            // numérotée ne se supprime jamais (obligation légale de
            // conservation) ; une correction passe par un avoir.
            // Un brouillon, sans numéro, se supprime définitivement (voir
            // FacturePolicy::delete()).

            $table->unique(['magasin_id', 'numero']);
            $table->index(['magasin_id', 'statut']);
            $table->index(['magasin_id', 'client_id']);
            $table->index(['magasin_id', 'cloture_caisse_id']);
            $table->index(['magasin_id', 'type_client', 'date_emission']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factures');
    }
};
