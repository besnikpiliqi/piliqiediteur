<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chaque document qu'un reçu de paiement règle (R2, voir
 * RecusDePaiement) : ce que les paiements du reçu lui imputent, le crédit
 * d'avoir qu'ils y imputent ou y rendent, et ce qui en restait dû après
 * cet encaissement, lus à la fin de sa transaction, une fois tout imputé.
 * Le reçu s'imprime depuis ces lignes et ses paiements, jamais recalculé.
 * On n'y fait qu'ajouter : la base refuse toute modification, toute
 * suppression et tout vidage (voir DeclencheursDeProtection).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignes_recu_paiement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->foreignId('recu_paiement_id')->constrained('recus_paiement')->restrictOnDelete();
            $table->foreignId('facture_id')->constrained('factures')->restrictOnDelete();
            // Ce que les paiements du reçu imputent sur le document, en
            // négatif pour une contre-passation.
            $table->decimal('montant', 12, 2);
            // Le crédit d'avoir que ces paiements y imputent (bons d'avoir
            // présentés, crédit réimputé après une contre-passation), en
            // négatif pour celui qu'une contre-passation rend.
            $table->decimal('credit', 12, 2);
            // Ce qui restait dû du document après cet encaissement.
            $table->decimal('reste_du', 12, 2);
            // Son rang parmi les documents du reçu, dans l'ordre où
            // l'encaissement les a réglés.
            $table->unsignedSmallInteger('ordre');
            $table->timestamps();

            $table->unique(['recu_paiement_id', 'facture_id']);
            $table->index(['magasin_id', 'facture_id']);
        });

        DeclencheursDeProtection::ajoutSeul('lignes_recu_paiement');
    }

    public function down(): void
    {
        Schema::dropIfExists('lignes_recu_paiement');
        DeclencheursDeProtection::retirer('lignes_recu_paiement');
    }
};
