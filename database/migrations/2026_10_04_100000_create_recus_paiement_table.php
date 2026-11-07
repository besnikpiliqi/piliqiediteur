<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les reçus de paiement (R2, décision de l'éditeur du 02/11/2026) : un par
 * encaissement fait après l'émission d'une facture ou d'un ticket (un geste
 * « Encaisser », toutes ses parts comprises, ou un paiement saisi dans
 * « Paiements »), un par contre-passation (voir RecusDePaiement). Chacun
 * naît dans la transaction de son encaissement, avant ses paiements, qui
 * le désignent (voir la migration create_paiements_table) ; les documents
 * qu'il règle, avec ce que ses paiements leur imputent et ce qui en restait
 * dû ensuite, s'y ajoutent à la fin (voir la migration
 * create_lignes_recu_paiement_table). On n'y fait qu'ajouter : la base
 * refuse toute modification, toute suppression et tout vidage (voir
 * DeclencheursDeProtection). Un reçu n'est pas une facture et ne change
 * jamais le document qu'il règle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recus_paiement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            // Le client des paiements, celui des documents réglés.
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            // Sa référence stable, dans la série RP du magasin, de l'année
            // de son enregistrement dans le calendrier du magasin
            // (RP-LYON1-2026-000001, voir DocumentNumberingService).
            $table->string('numero', 40);
            // encaissement, contre_passation (voir NatureRecuPaiement).
            $table->string('nature', 20);
            // Le jour de l'encaissement, dans le calendrier du magasin : le
            // jour de réception de ses paiements.
            $table->date('recu_le');
            // Le magasin qui encaisse et l'identité de la société, tels
            // qu'ils sont ce jour-là (voir Emetteur::enTableau()) : le reçu
            // les imprime pour toujours, quoi qu'ils deviennent.
            $table->json('emetteur');
            $table->foreignId('cree_par')->nullable()->constrained('users')->restrictOnDelete();
            // Le nom de cette personne au moment de l'opération (voir
            // FigeLeNomDeSesAuteurs) : l'historique ne le relit jamais sur
            // son compte.
            $table->string('cree_par_nom')->nullable();
            $table->timestamps();

            $table->unique(['magasin_id', 'numero']);
            $table->index(['magasin_id', 'client_id']);
        });

        DeclencheursDeProtection::ajoutSeul('recus_paiement');
    }

    public function down(): void
    {
        Schema::dropIfExists('recus_paiement');
        DeclencheursDeProtection::retirer('recus_paiement');
    }
};
