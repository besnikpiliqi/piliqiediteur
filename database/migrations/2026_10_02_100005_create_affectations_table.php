<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les affectations des comptes de la société à ses magasins (voir
 * Affectation) :
 * - un membre a une affectation par magasin où il travaille, avec son
 *   rôle de magasin (voir RoleMagasin) : responsable, sans aucune case,
 *   car il a toutes les autorisations de son magasin ; vendeur ou
 *   observateur, avec ses cases (voir autorisations_affectations), que son
 *   rôle n'a fait que précocher ;
 * - un administrateur a une affectation sans rôle par magasin de sa
 *   sélection, ou une seule, sans magasin, pour tous les magasins, présents
 *   et futurs, jamais les deux ; elles délimitent sa portée et portent les
 *   cases que le propriétaire lui a cochées ;
 * - le propriétaire et un expert-comptable n'en ont aucune.
 * La base refuse toute autre combinaison (voir GardesDesDroits, posées
 * avec la table des autorisations de société, la dernière qu'elles lisent).
 *
 * Les clés étrangères composites (societe_id, user_id) et (societe_id,
 * magasin_id) font refuser par la base l'affectation d'un compte au
 * magasin d'une autre société que la sienne ; une affectation sans
 * magasin n'en vérifie aucun (MATCH SIMPLE, sur les deux moteurs).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affectations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->foreignId('user_id');
            // null : tous les magasins de la société, présents et futurs
            // (administrateur seulement).
            $table->foreignId('magasin_id')->nullable();
            // RoleMagasin pour un membre ; null pour un administrateur.
            $table->string('role', 20)->nullable();
            // L'administrateur qui a nommé ce responsable, tant que la
            // nomination peut s'annuler (par lui ou par le propriétaire,
            // voir AnnulerUneNominationAction) : effacé dès que
            // l'affectation change de nouveau.
            $table->foreignId('nomme_par')->nullable()->constrained('users')->restrictOnDelete();
            // Le rôle et les cases d'avant, quand la nomination a promu un
            // vendeur ou un observateur de ce magasin : l'annulation les
            // rend. Null quand la nomination a créé l'affectation.
            $table->json('avant_la_nomination')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'magasin_id']);
            // Les responsables, les vendeurs et les observateurs d'un
            // magasin (voir EquipeDuMagasinResource et DestinatairesDesAlertes).
            $table->index(['magasin_id', 'role']);
            $table->foreign(['societe_id', 'user_id'])->references(['societe_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['societe_id', 'magasin_id'])->references(['societe_id', 'id'])->on('magasins')->restrictOnDelete();
        });

        // Une seule affectation à tous les magasins par compte : l'index
        // unique (user_id, magasin_id) ne compare pas deux magasin_id nuls.
        DB::statement('CREATE UNIQUE INDEX affectations_une_globale ON affectations (user_id) WHERE magasin_id IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('affectations');
    }
};
