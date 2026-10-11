<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les références des preuves qui justifient l'exonération d'une facture
 * émise (I4, voir PreuvesRequises) : la déclaration d'export (MRN) ou le
 * CN23 d'un export ou d'une livraison vers un DOM, les documents de
 * transport et l'attestation de réception de l'acquéreur d'une livraison
 * intracommunautaire (art. 45 bis du règlement 282/2011). On n'y fait
 * qu'ajouter : la base refuse toute modification, toute suppression et
 * tout vidage (voir DeclencheursDeProtection), et les clés étrangères
 * toute suppression d'un magasin, d'une facture ou d'un membre qu'une
 * preuve désigne. Seules les références s'enregistrent ici : les fichiers
 * viendront avec les pièces jointes isolées par magasin.
 *
 * type : TypePreuve ; date_document : la date du document, quand il en
 * porte une ; emetteur : qui l'a établi (douane, transporteur, assureur,
 * acquéreur) ; note_par : le membre qui l'a enregistrée.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preuves_operation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->foreignId('facture_id')->constrained('factures')->restrictOnDelete();
            $table->string('type', 30);
            $table->string('reference', 100);
            $table->date('date_document')->nullable();
            $table->string('emetteur', 150)->nullable();
            $table->foreignId('note_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['magasin_id', 'facture_id']);
        });

        DeclencheursDeProtection::ajoutSeul('preuves_operation');
    }

    public function down(): void
    {
        Schema::dropIfExists('preuves_operation');
        DeclencheursDeProtection::retirer('preuves_operation');
    }
};
