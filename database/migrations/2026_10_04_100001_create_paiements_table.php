<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use App\Domain\Facturation\Support\GardesDeLaVente;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les paiements reçus : on n'y fait qu'ajouter (art. 286 I-3° bis du CGI).
 * Chacun est signé à son enregistrement dans la chaîne des encaissements
 * du registre fiscal (voir EnregistrerPaiementAction) ; la base refuse
 * toute modification, toute suppression et tout vidage (voir
 * DeclencheursDeProtection), et les clés étrangères toute suppression
 * d'une fiche qu'il référence.
 *
 * Un paiement saisi par erreur se corrige par sa contre-passation (voir
 * ContrePasserPaiementAction) : un paiement opposé, de montant négatif,
 * qui désigne l'original par contre_passe_paiement_id. Un paiement n'a
 * qu'une contre-passation au plus.
 *
 * Le propriétaire, qui ne vend jamais, n'enregistre aucun paiement : la
 * base refuse un paiement à son nom, mais pas sa contre-passation, l'exact
 * opposé d'un paiement saisi par erreur (voir GardesDeLaVente).
 *
 * Un paiement reçu après l'émission de ce qu'il règle, et une
 * contre-passation, désignent leur reçu de paiement (R2, voir la migration
 * create_recus_paiement_table).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paiements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            $table->string('mode_paiement', 20);
            $table->decimal('montant', 12, 2);
            $table->string('reference', 100)->nullable();
            $table->text('notes')->nullable();
            $table->date('recu_le');
            $table->foreignId('cree_par')->nullable()->constrained('users')->restrictOnDelete();
            // Le nom de cette personne au moment de l'opération (voir
            // FigeLeNomDeSesAuteurs) : l'historique ne le relit jamais sur
            // son compte.
            $table->string('cree_par_nom')->nullable();
            $table->foreignId('contre_passe_paiement_id')->nullable()->unique()->constrained('paiements')->restrictOnDelete();
            // Le reçu de paiement de l'encaissement fait après l'émission
            // dont il est une part, ou de la contre-passation (voir
            // RecusDePaiement) ; aucun pour un paiement reçu à l'émission,
            // que le document imprime lui-même.
            $table->foreignId('recu_paiement_id')->nullable()->constrained('recus_paiement')->restrictOnDelete();
            $table->timestamps();

            $table->index(['magasin_id', 'client_id']);
            $table->index(['magasin_id', 'recu_le']);
            $table->index(['magasin_id', 'recu_paiement_id']);
            // Les encaissements d'une période, dans l'ordre de leur
            // enregistrement (relevé des encaissements du téléchargement
            // par période, voir DocumentsDeLaPeriode::paiementsVisibles()).
            $table->index(['magasin_id', 'created_at']);
        });

        DeclencheursDeProtection::ajoutSeul('paiements');
        GardesDeLaVente::poser('paiements');
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements');
        DeclencheursDeProtection::retirer('paiements');
        GardesDeLaVente::retirer('paiements');
    }
};
