<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La TVA d'un avoir fournisseur, taux par taux (décision de l'éditeur du
 * 02/11/2026, T1) : la base et la TVA qu'écrit la note d'avoir du
 * fournisseur, qui prime, et celle que PILIQI propose pour les articles
 * retournés, gardée pour la preuve (voir TvaDuFournisseur et
 * EmettreAvoirFournisseurAction). Mêmes colonnes que la TVA d'une facture
 * d'achat (voir la migration create_taux_factures_achat_table).
 *
 * Unique par avoir et par taux, et limitée au magasin par son avoir ; une
 * société de services, qui n'a pas d'avoir fournisseur (voir
 * GardesDesTypesDActivite), n'en a aucune, par la clé étrangère.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taux_avoirs_fournisseur', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            $table->foreignId('avoir_fournisseur_id')->constrained('avoirs_fournisseur')->cascadeOnDelete();
            $table->decimal('taux_tva', 5, 2);
            // La somme exacte des HT retenus de ses lignes.
            $table->decimal('base_ht', 12, 2);
            // La TVA retenue : celle de la note d'avoir du fournisseur.
            $table->decimal('montant_tva', 12, 2);
            // La TVA que PILIQI propose : la somme de celles de ses lignes.
            $table->decimal('montant_tva_calcule', 12, 2);
            // La TVA que la personne a saisie, quand elle l'a saisie.
            $table->decimal('montant_tva_saisi', 12, 2)->nullable();
            // La confirmation d'un écart au-delà de la tolérance (voir la
            // migration create_taux_factures_achat_table).
            $table->foreignId('ecart_confirme_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ecart_confirme_par_nom')->nullable();
            $table->dateTime('ecart_confirme_le')->nullable();
            $table->char('ecart_confirme_empreinte', 64)->nullable();
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->unique(['avoir_fournisseur_id', 'taux_tva']);
            $table->index(['magasin_id', 'avoir_fournisseur_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taux_avoirs_fournisseur');
    }
};
