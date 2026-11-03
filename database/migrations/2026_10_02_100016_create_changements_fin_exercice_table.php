<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les changements légaux de la date de clôture de l'exercice d'une société
 * (voir ChangementFinExercice et ChangerFinExerciceAction), sur
 * procès-verbal, par la procédure de l'éditeur, son mois de fin
 * d'exercice étant verrouillé dès la première vente de l'un de ses
 * magasins : l'ancien et le nouveau mois, l'exercice en cours au jour de
 * la procédure, son début et sa fin avant le changement, sa fin après,
 * tirée du procès-verbal, la date de la décision, la référence de la
 * pièce, l'expert-comptable de la société qui a validé la durée de
 * l'exercice transitoire, la justification et les opérateurs de l'éditeur.
 * Les jours sont des jours de Paris (« AAAA-MM-JJ »), le calendrier de la
 * société. En ajout seul : la base refuse toute modification, toute
 * suppression et tout vidage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('changements_fin_exercice', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->unsignedTinyInteger('ancien_mois');
            $table->unsignedTinyInteger('nouveau_mois');
            $table->date('debut_exercice_en_cours');
            $table->date('fin_exercice_en_cours_avant');
            $table->date('fin_exercice_en_cours_apres');
            $table->date('decision_du');
            $table->string('piece_reference', 100);
            $table->string('duree_validee_par', 150);
            $table->text('justification');
            $table->json('operateur');
            $table->timestamp('created_at');

            $table->index(['societe_id', 'id']);
        });

        DeclencheursDeProtection::ajoutSeul('changements_fin_exercice');
    }

    public function down(): void
    {
        Schema::dropIfExists('changements_fin_exercice');
        DeclencheursDeProtection::retirer('changements_fin_exercice');
    }
};
