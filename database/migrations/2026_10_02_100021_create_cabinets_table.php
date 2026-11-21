<?php

use App\Domain\Cabinets\Support\GardesDesCabinets;
use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La vérification propre à un cabinet d'expertise comptable (voir
 * Cabinet) : son code d'activité lu au répertoire Sirene à la confirmation
 * de son inscription et son contrôle, et son inscription au tableau de
 * l'Ordre des experts-comptables, vérifiée par l'annuaire de l'Ordre ou
 * par l'éditeur, refusée ou retirée. Une ligne par cabinet, née avec la
 * société (voir CreerSocieteAction) ; la base refuse une ligne pour une
 * société d'un autre type (voir GardesDesCabinets) et sa suppression.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cabinets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->unique()->constrained('societes')->restrictOnDelete();
            // Le code d'activité principale et sa nomenclature lus au
            // répertoire Sirene à la confirmation de l'inscription (69.20Z,
            // NAFRev2), les mêmes que l'entrée d'audit de l'inscription.
            $table->string('code_activite', 6)->nullable();
            $table->string('nomenclature_activite', 10)->nullable();
            // Posée quand le répertoire a donné 69.20Z en NAF rév. 2, ou par
            // la procédure de l'éditeur pour un code absent ou d'une autre
            // nomenclature.
            $table->timestamp('activite_controlee_le')->nullable();
            // Le n° d'inscription au tableau et le conseil régional de
            // l'Ordre, déclarés par le propriétaire ou constatés.
            $table->string('numero_ordre', 30)->nullable();
            $table->string('conseil_regional_ordre', 100)->nullable();
            // Une vérification attend l'éditeur (pilote « editeur » de
            // l'annuaire de l'Ordre).
            $table->timestamp('ordre_demande_le')->nullable();
            $table->timestamp('ordre_verifie_le')->nullable();
            // VoieDeVerificationOrdre : annuaire ou editeur.
            $table->string('ordre_voie', 20)->nullable();
            // Le dernier refus de la vérification et son motif, effacés par
            // une nouvelle demande.
            $table->timestamp('ordre_refuse_le')->nullable();
            $table->string('ordre_motif_refus', 255)->nullable();
            // La radiation de l'Ordre constatée par l'éditeur.
            $table->timestamp('ordre_retire_le')->nullable();
            $table->timestamps();
        });

        DeclencheursDeProtection::sansSuppression('cabinets');

        // Seul un cabinet d'expertise comptable a cette ligne.
        GardesDesCabinets::poser(GardesDesCabinets::CABINETS);
    }

    public function down(): void
    {
        GardesDesCabinets::retirer(GardesDesCabinets::CABINETS);
        Schema::dropIfExists('cabinets');
        DeclencheursDeProtection::retirer('cabinets');
    }
};
