<?php

use App\Domain\Cabinets\Support\GardesDesCabinets;
use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use App\Domain\Tenancy\Support\GardesDesTypesDActivite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factures_achat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->foreignId('fournisseur_id');
            $table->foreignId('cree_par')->nullable()->constrained('users')->restrictOnDelete();
            // Le nom de cette personne au moment de l'opération (voir
            // FigeLeNomDeSesAuteurs) : l'historique ne le relit jamais sur
            // son compte.
            $table->string('cree_par_nom')->nullable();
            // Une facture saisie à la main reste modifiable même validée
            // (voir FactureAchat::estModifiable()), une facture récupérée
            // par API ne l'est jamais.
            $table->string('source', 20)->default('manuelle');
            // Notre propre référence interne, attribuée uniquement à la
            // validation (voir ValiderFactureAchatAction) -- jamais au
            // brouillon, même logique que Facture::numero.
            $table->string('numero', 40)->nullable();
            // Le numéro de facture du FOURNISSEUR lui-même (format libre,
            // variable selon le fournisseur) : pure donnée de
            // rapprochement, distincte de notre numérotation interne.
            $table->string('numero_fournisseur', 60)->nullable();
            $table->string('statut', 20)->default('brouillon');
            // La date de la facture du fournisseur, obligatoire pour une
            // saisie à la main (voir CreerFactureAchatAction) : le
            // téléchargement des documents par période classe un achat
            // par elle ; celle d'un achat reçu par l'API peut manquer, il
            // se classe alors au jour de sa validation (voir
            // DocumentsDeLaPeriode).
            $table->date('date_facture')->nullable();
            // L'instant de la validation, en UTC (cast Instant).
            $table->dateTime('date_validation')->nullable();
            // Son rang de validation dans le magasin (1, 2, 3…, toutes
            // années confondues), pris sous verrou à la validation (voir
            // ValiderFactureAchatAction) : il ne recule jamais, même quand
            // deux validations tombent dans la même seconde, et le dernier
            // achat validé d'un produit se choisit par lui (voir
            // Produit::achatsValides()).
            $table->unsignedBigInteger('rang_validation')->nullable();
            // Le plus grand identifiant du grand livre du stock du magasin
            // au moment de sa validation (0 s'il était vide), lu sous le
            // verrou de ses produits (voir ValiderFactureAchatAction) :
            // la place de sa réception dans l'histoire de chacun de ses
            // produits, même de celui qu'elle n'a pas fait entrer en stock,
            // faute de suivi, et dont elle fixe pourtant le coût moyen (voir
            // StockLedgerService::coutMoyenRejoue()). Posé une fois, figé
            // ensuite.
            $table->unsignedBigInteger('repere_grand_livre')->nullable();
            $table->string('devise', 3)->default('EUR');
            $table->text('notes')->nullable();
            // Sommes exactes de ses taux (voir la migration
            // create_taux_factures_achat_table) : la base et la TVA de
            // chaque taux, celles qu'écrit la facture du fournisseur.
            $table->decimal('total_ht', 12, 2)->default(0);
            $table->decimal('total_tva', 12, 2)->default(0);
            $table->decimal('total_ttc', 12, 2)->default(0);
            // « TVA à vérifier » : un HT de ligne ou une TVA de taux saisis
            // au-delà de la tolérance, confirmés (voir TvaDuFournisseur),
            // visible de tous ceux qui voient les achats, cabinet lié
            // compris ; elle se lève quand la facture est corrigée dans la
            // tolérance.
            $table->boolean('tva_a_verifier')->default(false);
            // « Pièce du fournisseur manquante » : une facture saisie à la
            // main sans la pièce du fournisseur jointe (décision de
            // l'éditeur du 03/11/2026, F1 = b) ; elle s'enregistre et se
            // valide quand même, et la marque se lève à l'ajout de la
            // première pièce (voir PiecesDuFournisseur::marquer()). Jamais
            // sur une facture reçue par l'API.
            $table->boolean('piece_manquante')->default(false);
            // Réduction accordée par le fournisseur pour un paiement rapide :
            // elle baisse le coût d'achat des produits (voir
            // LigneAchat::coutUnitaireHt()), pas les totaux de la facture.
            $table->decimal('escompte_pourcentage', 5, 2)->default(0);
            $table->timestamps();

            $table->unique(['magasin_id', 'numero']);
            $table->unique(['magasin_id', 'rang_validation']);
            // La clé que référencent les pièces du fournisseur avec leur
            // magasin (voir la migration create_pieces_fournisseur_table) :
            // une pièce ne se joint qu'à une facture de son magasin.
            $table->unique(['magasin_id', 'id']);
            $table->index(['magasin_id', 'fournisseur_id']);
            $table->index(['magasin_id', 'tva_a_verifier']);
            $table->index(['magasin_id', 'piece_manquante']);
            $table->index(['magasin_id', 'statut', 'date_facture']);

            $table->foreign(['magasin_id', 'fournisseur_id'])->references(['magasin_id', 'id'])->on('fournisseurs')->restrictOnDelete();
        });

        // Seule une société qui a des articles achète des marchandises
        // (voir TypeDActivite::aDesArticles()).
        GardesDesTypesDActivite::poser(GardesDesTypesDActivite::FACTURES_ACHAT);
        GardesDesCabinets::poserLesMagasinsOuverts('factures_achat');
        DeclencheursDeProtection::colonneFigee('factures_achat', 'repere_grand_livre');
    }

    public function down(): void
    {
        GardesDesCabinets::retirerLesMagasinsOuverts('factures_achat');
        GardesDesTypesDActivite::retirer(GardesDesTypesDActivite::FACTURES_ACHAT);
        Schema::dropIfExists('factures_achat');
        DeclencheursDeProtection::retirerLaColonneFigee('factures_achat', 'repere_grand_livre');
    }
};
