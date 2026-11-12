<?php

use App\Domain\Tenancy\Support\GardesDesTypesDActivite;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avoirs_fournisseur', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            // restrictOnDelete : une facture d'achat validée n'est de toute
            // façon jamais supprimable (voir FactureAchatPolicy), mais on ne
            // veut surtout pas qu'une suppression quelconque efface
            // silencieusement la trace d'un avoir fournisseur.
            $table->foreignId('facture_achat_id');
            $table->foreignId('cree_par')->nullable()->constrained('users')->restrictOnDelete();
            // Le nom de cette personne au moment de l'opération (voir
            // FigeLeNomDeSesAuteurs) : l'historique ne le relit jamais sur
            // son compte.
            $table->string('cree_par_nom')->nullable();
            // Traçabilité (voir SourceAvoirFournisseur) : saisi à la main
            // aujourd'hui ; ceux reçus par la plateforme agréée ou par
            // l'API viendront.
            $table->string('source', 20)->default('manuelle');
            $table->string('numero', 40)->nullable();
            // Le numéro de l'avoir chez le FOURNISSEUR lui-même (format
            // libre), facultatif comme celui de la facture d'achat : il
            // rapprochera l'avoir que le fournisseur enverra par la
            // plateforme de facturation électronique de celui saisi ici.
            $table->string('numero_fournisseur', 60)->nullable();
            $table->string('statut', 20)->default('brouillon');
            // L'instant de l'émission, en UTC (cast Instant) : les retours
            // d'une ligne d'achat se lisent dans l'ordre de leur émission
            // (voir FactureAchatResource).
            $table->dateTime('date_emission')->nullable();
            $table->string('devise', 3)->default('EUR');
            $table->text('motif')->nullable();
            // Sommes exactes de ses taux (voir la migration
            // create_taux_avoirs_fournisseur_table) : la base et la TVA de
            // chaque taux, celles qu'écrit la note d'avoir du fournisseur.
            $table->decimal('total_ht', 12, 2)->default(0);
            $table->decimal('total_tva', 12, 2)->default(0);
            $table->decimal('total_ttc', 12, 2)->default(0);
            // « TVA à vérifier », comme sur la facture d'achat (voir
            // TvaDuFournisseur).
            $table->boolean('tva_a_verifier')->default(false);
            // « Pièce du fournisseur manquante », comme sur la facture
            // d'achat : un avoir saisi à la main sans la note d'avoir du
            // fournisseur jointe (voir PiecesDuFournisseur::marquer()).
            $table->boolean('piece_manquante')->default(false);
            $table->timestamps();

            $table->unique(['magasin_id', 'numero']);
            // La clé que référencent les pièces du fournisseur avec leur
            // magasin (voir la migration create_pieces_fournisseur_table).
            $table->unique(['magasin_id', 'id']);
            $table->index(['magasin_id', 'facture_achat_id']);
            $table->index(['magasin_id', 'tva_a_verifier']);
            $table->index(['magasin_id', 'piece_manquante']);
            $table->index(['magasin_id', 'statut', 'date_emission']);

            $table->foreign(['magasin_id', 'facture_achat_id'])->references(['magasin_id', 'id'])->on('factures_achat')->restrictOnDelete();
        });

        // Seule une société qui a des articles reçoit un avoir fournisseur
        // (voir TypeDActivite::aDesArticles()).
        GardesDesTypesDActivite::poser(GardesDesTypesDActivite::AVOIRS_FOURNISSEUR);
    }

    public function down(): void
    {
        GardesDesTypesDActivite::retirer(GardesDesTypesDActivite::AVOIRS_FOURNISSEUR);
        Schema::dropIfExists('avoirs_fournisseur');
    }
};
