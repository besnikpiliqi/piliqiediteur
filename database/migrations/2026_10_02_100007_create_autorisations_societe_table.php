<?php

use App\Domain\Tenancy\Support\GardesDesDroits;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les autorisations de société cochées pour un administrateur par le
 * propriétaire (voir AutorisationsSociete et CreerAdministrateurAction) :
 * gérer les magasins, nommer et retirer les responsables, gérer les
 * paramètres de la société. Le propriétaire les a toutes sans case ; un
 * membre ou un expert-comptable n'en a aucune. Chacune référence le
 * référentiel des autorisations de société, et la clé étrangère composite
 * (societe_id, user_id) les garde dans la société du compte.
 *
 * Cette table est la dernière que lisent les gardes de la base qui lient
 * le rôle de société d'un compte à ses affectations et à ses autorisations
 * (voir GardesDesDroits) : elles se posent ici, et se retirent avant elle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autorisations_societe', function (Blueprint $table) {
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->foreignId('user_id');
            $table->string('autorisation', 60);

            $table->primary(['user_id', 'autorisation']);
            $table->foreign(['societe_id', 'user_id'])->references(['societe_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign('autorisation')->references('cle')->on('referentiel_autorisations_societe')->restrictOnDelete();
        });

        GardesDesDroits::poser();
    }

    public function down(): void
    {
        GardesDesDroits::retirer();
        Schema::dropIfExists('autorisations_societe');
    }
};
