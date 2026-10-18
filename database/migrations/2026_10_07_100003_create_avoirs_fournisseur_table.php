<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('avoirs_fournisseur', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            // restrictOnDelete : une facture d'achat validée n'est de toute
            // façon jamais supprimable (voir FactureAchatPolicy), mais on ne
            // veut surtout pas qu'une suppression quelconque efface
            // silencieusement la trace d'un avoir fournisseur.
            $table->foreignId('facture_achat_id')->constrained('factures_achat')->restrictOnDelete();
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
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
            $table->timestamp('date_emission')->nullable();
            $table->string('devise', 3)->default('EUR');
            $table->text('motif')->nullable();
            $table->decimal('total_ht', 12, 2)->default(0);
            $table->decimal('total_tva', 12, 2)->default(0);
            $table->decimal('total_ttc', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['magasin_id', 'numero']);
            $table->index(['magasin_id', 'facture_achat_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('avoirs_fournisseur');
    }
};
