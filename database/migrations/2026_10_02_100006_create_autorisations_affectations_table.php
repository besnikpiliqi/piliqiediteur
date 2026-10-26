<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les autorisations cochées pour l'affectation d'un vendeur, d'un
 * observateur ou d'un administrateur (voir Affectation::cocher()) : elles
 * valent dans le magasin de l'affectation, ou dans tous les magasins de la
 * société pour une affectation sans magasin, et disparaissent avec leur
 * affectation. Chacune référence le référentiel des autorisations de
 * magasin : la base refuse une autorisation inconnue, et une autorisation
 * encore cochée ne se retire pas du référentiel. Une affectation de
 * responsable n'en porte jamais (voir GardesDesDroits). L'index sur
 * l'autorisation sert aux destinataires d'une alerte (voir
 * DestinatairesDesAlertes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autorisations_affectations', function (Blueprint $table) {
            $table->foreignId('affectation_id')->constrained('affectations')->cascadeOnDelete();
            $table->string('autorisation', 60);

            $table->primary(['affectation_id', 'autorisation']);
            $table->index('autorisation');
            $table->foreign('autorisation')->references('cle')->on('referentiel_autorisations_magasin')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autorisations_affectations');
    }
};
