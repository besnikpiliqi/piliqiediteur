<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursAjoutSeul;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les paiements reçus : on n'y fait qu'ajouter (art. 286 I-3° bis du CGI).
 * Chacun est signé à son enregistrement dans la chaîne des encaissements
 * du registre fiscal (voir EnregistrerPaiementAction) ; la base refuse
 * toute modification et toute suppression (voir DeclencheursAjoutSeul), et
 * les clés étrangères toute suppression d'une fiche qu'il référence.
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
            $table->timestamps();

            $table->index(['magasin_id', 'client_id']);
            $table->index(['magasin_id', 'recu_le']);
        });

        DeclencheursAjoutSeul::poser('paiements');
    }

    public function down(): void
    {
        Schema::dropIfExists('paiements');
    }
};
