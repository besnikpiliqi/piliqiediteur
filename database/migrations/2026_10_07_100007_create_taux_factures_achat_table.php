<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La TVA d'une facture d'achat, taux par taux (décision de l'éditeur du
 * 02/11/2026, T1) : la base et la TVA qu'écrit la facture du fournisseur,
 * seule TVA déductible, et celle que PILIQI calcule, gardée pour la preuve
 * (voir TvaDuFournisseur). Une ligne par taux de la facture : la base est
 * la somme exacte des HT de ses lignes, la TVA retenue celle que la
 * personne a saisie (montant_tva_saisi), sinon la calculée, base x taux
 * arrondie une fois. Un écart au-delà de la tolérance se confirme : qui,
 * quand, et l'empreinte de ce qui a été confirmé, pour ne pas redemander
 * la confirmation du même écart.
 *
 * Unique par facture et par taux, et limitée au magasin par sa facture ;
 * une société de services, qui n'a pas de facture d'achat (voir
 * GardesDesTypesDActivite), n'en a aucune, par la clé étrangère.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taux_factures_achat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->foreignId('facture_achat_id');
            $table->decimal('taux_tva', 5, 2);
            // La somme exacte des HT retenus de ses lignes.
            $table->decimal('base_ht', 12, 2);
            // La TVA retenue : celle de la facture du fournisseur.
            $table->decimal('montant_tva', 12, 2);
            // La TVA que PILIQI calcule, base x taux arrondie une fois.
            $table->decimal('montant_tva_calcule', 12, 2);
            // La TVA que la personne a saisie, quand elle l'a saisie.
            $table->decimal('montant_tva_saisi', 12, 2)->nullable();
            // La confirmation d'un écart au-delà de la tolérance (voir
            // TvaDuFournisseur) : son auteur, son nom au moment de
            // l'opération (voir FigeLeNomDeSesAuteurs), son instant, en UTC
            // (cast Instant), et l'empreinte de l'écart confirmé.
            $table->foreignId('ecart_confirme_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('ecart_confirme_par_nom')->nullable();
            $table->dateTime('ecart_confirme_le')->nullable();
            $table->char('ecart_confirme_empreinte', 64)->nullable();
            // Son rang, dans l'ordre où le taux apparaît sur la facture.
            $table->unsignedSmallInteger('ordre')->default(0);
            $table->timestamps();

            $table->unique(['facture_achat_id', 'taux_tva']);
            $table->index(['magasin_id', 'facture_achat_id']);

            $table->foreign(['magasin_id', 'facture_achat_id'])->references(['magasin_id', 'id'])->on('factures_achat')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taux_factures_achat');
    }
};
