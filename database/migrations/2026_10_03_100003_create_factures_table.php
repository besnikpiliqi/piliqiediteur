<?php

use App\Domain\Facturation\Support\GardesDeLaVente;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les factures et les tickets de caisse d'un magasin. Le propriétaire, qui
 * ne vend jamais, n'en crée aucun : la base refuse une facture créée à son
 * nom ou qu'on lui attribue ensuite (voir GardesDeLaVente).
 */
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
            // La société vendait-elle en franchise en base de TVA (lignes à
            // 0 %, mention « TVA non applicable », voir ContexteDeVente) ?
            // Écrit avec le type du client à chaque enregistrement du
            // brouillon, ou repris du devis converti, et contrôlé à
            // l'émission contre le régime de la société à cet instant (voir
            // EmettreFactureAction) ; ensuite, la mention se lit ici, jamais
            // sur le régime actuel de la société (voir MentionsFiscales).
            $table->boolean('franchise_en_base')->default(false);
            // La nature de la vente (NatureOperation) : en France, par
            // défaut, ou hors de la TVA française (export, livraison vers un
            // DOM, livraison intracommunautaire, prestation à un
            // professionnel établi hors de France), écrite à chaque
            // enregistrement du brouillon et contrôlée à l'émission (voir
            // ControleNatureOperation) ; l'avoir qui corrige la facture la
            // reprend.
            $table->string('nature_operation', 40)->default('vente_france');
            // Où et comment partent les biens d'un export, d'une livraison
            // vers un DOM ou d'une livraison intracommunautaire (voir
            // NatureOperation::exigeUneLivraison()) : vides pour toute autre
            // nature. transport_par : TransportPar.
            $table->string('livraison_adresse')->nullable();
            $table->string('livraison_complement')->nullable();
            $table->string('livraison_code_postal', 10)->nullable();
            $table->string('livraison_ville', 100)->nullable();
            $table->string('livraison_pays', 2)->nullable();
            $table->date('livraison_date')->nullable();
            $table->string('transport_par', 20)->nullable();
            // La vérification sur VIES du n° de TVA du client que retient
            // l'émission d'une livraison intracommunautaire ou d'une
            // prestation autoliquidée : valide, du jour de l'émission, pour
            // le n° que la facture copie (voir ControleNatureOperation).
            // Choisie dans le magasin de la facture, jamais reçue d'un
            // formulaire ; une preuve ne se supprime pas.
            $table->foreignId('consultation_vies_id')->nullable()->constrained('consultations_vies')->restrictOnDelete();
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
            // Le nom de cette personne au moment de l'opération (voir
            // FigeLeNomDeSesAuteurs) : l'historique ne le relit jamais sur
            // son compte.
            $table->string('cree_par_nom')->nullable();
            $table->string('numero', 40)->nullable();
            $table->string('statut', 20)->default('brouillon');
            // L'instant du registre fiscal à l'émission, en UTC à la seconde
            // (voir HorodateSonEmission) : une date et une heure sans fuseau,
            // écrites et relues telles quelles.
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

        GardesDeLaVente::poser('factures');
    }

    public function down(): void
    {
        Schema::dropIfExists('factures');
        GardesDeLaVente::retirer('factures');
    }
};
