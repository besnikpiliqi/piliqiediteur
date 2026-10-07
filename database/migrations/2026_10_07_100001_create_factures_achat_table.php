<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('factures_achat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            $table->foreignId('fournisseur_id')->constrained('fournisseurs')->restrictOnDelete();
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
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
            $table->date('date_facture')->nullable();
            $table->timestamp('date_validation')->nullable();
            $table->string('devise', 3)->default('EUR');
            $table->text('notes')->nullable();
            $table->decimal('total_ht', 12, 2)->default(0);
            $table->decimal('total_tva', 12, 2)->default(0);
            $table->decimal('total_ttc', 12, 2)->default(0);
            $table->timestamps();

            $table->unique(['magasin_id', 'numero']);
            $table->index(['magasin_id', 'fournisseur_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('factures_achat');
    }
};
