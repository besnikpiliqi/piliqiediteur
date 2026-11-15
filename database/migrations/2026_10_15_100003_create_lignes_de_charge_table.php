<?php

use App\Domain\Charges\Enums\TauxDeCharge;
use App\Domain\Charges\Support\GardesDesCharges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les lignes d'une charge, une par taux de TVA (décision de l'éditeur du
 * 14/11/2026, lot CHARGES, modèle C2 ; voir LigneDeCharge) : HT, taux en
 * centièmes de point, TVA, TTC et TVA déductible, en centimes, tels que la
 * pièce les écrit, la TVA déductible jamais au-delà de la TVA. Elles se
 * réécrivent en entier à chaque saisie ; celles d'une charge validée ne
 * changent que par une correction, dont elles portent la version (voir
 * GardesDesCharges). La clé étrangère composite garde chaque ligne dans
 * la société de sa charge.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lignes_de_charge', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->unsignedBigInteger('charge_id');
            // La correction de la charge qui a écrit la ligne (0 avant toute
            // correction).
            $table->unsignedInteger('version')->default(0);
            $table->unsignedSmallInteger('rang');
            $table->unsignedSmallInteger('taux');
            $table->bigInteger('ht');
            $table->bigInteger('tva');
            $table->bigInteger('ttc');
            $table->bigInteger('tva_deductible');
            $table->boolean('tva_a_verifier')->default(false);

            $table->foreign(['societe_id', 'charge_id'])->references(['societe_id', 'id'])->on('charges')->restrictOnDelete();
            $table->unique(['charge_id', 'version', 'taux']);
            $table->index(['societe_id', 'charge_id']);
        });

        $taux = implode(', ', array_map(static fn (TauxDeCharge $taux): string => (string) $taux->value, TauxDeCharge::cases()));
        DB::statement("ALTER TABLE lignes_de_charge ADD CONSTRAINT lignes_de_charge_taux_connu CHECK (taux IN ({$taux}))");
        DB::statement('ALTER TABLE lignes_de_charge ADD CONSTRAINT lignes_de_charge_montants CHECK (ht >= 0 AND tva >= 0 AND ttc >= 0 AND tva_deductible >= 0 AND tva_deductible <= tva)');

        GardesDesCharges::poserSurLesLignes();
    }

    public function down(): void
    {
        GardesDesCharges::retirerDesLignes();
        Schema::dropIfExists('lignes_de_charge');
    }
};
