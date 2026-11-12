<?php

use App\Domain\Facturation\Support\GardesDeLaVente;
use App\Domain\Facturation\Support\GardesDesDocumentsEmis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les factures et les tickets de caisse d'un magasin. Le propriétaire n'en
 * crée aucun dans un magasin réel, où il ne vend jamais : la base y refuse
 * une facture créée à son nom, qu'on lui attribue ensuite ou qu'on émet à
 * son nom ; il ne vend que dans le magasin de démonstration (voir
 * GardesDeLaVente). Une facture rectificative, après la correction du
 * SIREN de la société par l'éditeur, désigne la facture de son magasin
 * qu'elle rectifie, et une facture n'en a qu'une (voir
 * RegularisationDUneFacture).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factures', function (Blueprint $table) {
            $table->id();
            // restrictOnDelete partout : une facture émise ne se réécrit ni
            // ne disparaît (art. 286 I-3° bis du CGI, voir
            // ProtegeLesDonneesValidees et, en base, GardesDesDocumentsEmis),
            // pas même par la suppression d'une fiche qu'elle référence.
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->foreignId('client_id');
            $table->foreignId('devis_id')->nullable();
            // D'après quel devis la facture est établie (voir
            // CreerFactureDepuisDevisAction) : son numéro et le jour, au
            // calendrier du magasin, de son acceptation, recopiés à la
            // transformation et figés à l'émission, que la fiche et le PDF
            // impriment (« Établie d'après le devis n° … accepté le … ») ;
            // tous deux vides pour une facture qui ne vient pas d'un devis
            // (contrainte factures_devis_d_origine).
            $table->string('devis_numero', 40)->nullable();
            $table->date('devis_accepte_le')->nullable();
            // La facture que rectifie une facture rectificative, émise après
            // la correction du SIREN de la société par l'éditeur (voir
            // EmettreLaFactureRectificativeAction) : une facture de son
            // magasin (clé étrangère sur le magasin et la facture), qui n'en
            // a qu'une (index unique) ; vide pour toute autre facture.
            $table->unsignedBigInteger('facture_rectifiee_id')->nullable();
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
            $table->foreignId('consultation_vies_id')->nullable();
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
            // écrite sur une facture émise, une seule fois : posée par la
            // clôture vers le ticket Z qu'elle vient d'écrire, le dernier
            // de son magasin, puis figée, sauf pour se défaire d'un ticket Z
            // que rien ne signe (voir GardesDesClotures, posées avec la
            // table du registre, qu'elles lisent). L'étape des
            // clôtures la supprimera : les documents d'un ticket Z se liront
            // dans sa fenêtre signée du registre.
            $table->foreignId('cloture_caisse_id')->nullable();
            // Qui a créé la facture : nécessaire pour restreindre la
            // visibilité d'un vendeur à ses propres ventes par défaut
            // (voir FacturePolicy et FACTURE_VIEW_TOUTES).
            $table->foreignId('cree_par')->nullable()->constrained('users')->restrictOnDelete();
            // Le nom de cette personne au moment de l'opération (voir
            // FigeLeNomDeSesAuteurs) : l'historique ne le relit jamais sur
            // son compte.
            $table->string('cree_par_nom')->nullable();
            $table->string('numero', 40)->nullable();
            $table->string('statut', 30)->default('brouillon');
            // L'instant du registre fiscal à l'émission, en UTC (cast
            // Instant, voir HorodateSonEmission).
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
            // la facture (voir Facture::montantPayeEnCentimes(),
            // montantCrediteParAvoirEnCentimes() et
            // montantRestantEnCentimes()), qui ne se modifient ni ne se
            // suppriment.
            $table->timestamps();
            // PAS de softDeletes() ici, volontairement : une facture
            // numérotée ne se supprime jamais (obligation légale de
            // conservation), ce que la base refuse aussi (voir
            // GardesDesDocumentsEmis) ; une correction passe par un avoir.
            // Un brouillon, sans numéro, se supprime définitivement (voir
            // FacturePolicy::delete()).

            $table->unique(['magasin_id', 'numero']);
            $table->unique(['magasin_id', 'id']);
            $table->unique('facture_rectifiee_id');
            $table->foreign(['magasin_id', 'facture_rectifiee_id'])->references(['magasin_id', 'id'])->on('factures')->restrictOnDelete();
            $table->index(['magasin_id', 'statut']);
            $table->index(['magasin_id', 'client_id']);
            $table->index(['magasin_id', 'cloture_caisse_id']);
            $table->index(['magasin_id', 'type_client', 'date_emission']);
            // Les ventes d'une période, tous clients (téléchargement par
            // période, voir DocumentsDeLaPeriode).
            $table->index(['magasin_id', 'date_emission']);

            $table->foreign(['magasin_id', 'client_id'])->references(['magasin_id', 'id'])->on('clients')->restrictOnDelete();
            $table->foreign(['magasin_id', 'devis_id'])->references(['magasin_id', 'id'])->on('devis')->restrictOnDelete();
            $table->foreign(['magasin_id', 'consultation_vies_id'])->references(['magasin_id', 'id'])->on('consultations_vies')->restrictOnDelete();
            $table->foreign(['magasin_id', 'cloture_caisse_id'])->references(['magasin_id', 'id'])->on('clotures_caisse')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE factures ADD CONSTRAINT factures_devis_d_origine CHECK ((devis_id IS NULL AND devis_numero IS NULL AND devis_accepte_le IS NULL) OR (devis_id IS NOT NULL AND devis_numero IS NOT NULL AND devis_accepte_le IS NOT NULL))');

        GardesDeLaVente::poser('factures');
        GardesDesDocumentsEmis::poser('factures');
    }

    public function down(): void
    {
        Schema::dropIfExists('factures');
        GardesDeLaVente::retirer('factures');
        GardesDesDocumentsEmis::retirer('factures');
    }
};
