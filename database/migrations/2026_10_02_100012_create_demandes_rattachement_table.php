<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les propositions de rattachement qu'une société adresse au compte d'une
 * personne qu'une société a retirée, la même ou une autre (voir
 * DemandeRattachement et ProposerUnRattachementAction) : le lien envoyé à
 * la personne en porte le jeton, dont seule l'empreinte SHA-256 est
 * gardée ; il vaut sept jours et ne sert qu'une fois. Le rôle et les
 * affectations proposés y sont notés, revérifiés à l'acceptation avec les
 * droits de leur auteur (voir AccepterUnRattachementAction). Une demande
 * ne s'efface jamais.
 *
 * Les clés étrangères vers les comptes sont simples : le compte visé
 * appartient encore, à la proposition, à la société qui l'a retiré, et
 * l'auteur, compte de la société qui propose à ce moment, peut la quitter
 * ensuite (une demande ne s'efface jamais).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demandes_rattachement', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('demande_par')->constrained('users')->restrictOnDelete();
            // Le nom de l'auteur au moment de la proposition (voir
            // FigeLeNomDeSesAuteurs) : l'historique ne le relit jamais sur
            // son compte.
            $table->string('demande_par_nom');
            // Le rôle proposé dans la société : membre ou expert_comptable
            // (voir RoleSociete).
            $table->string('role_societe', 20);
            // Les affectations proposées, [{magasin_id, role, autorisations}] :
            // autorisations vides pour un responsable, liste vide pour un
            // expert-comptable.
            $table->json('affectations');
            // SHA-256 du jeton du lien, jamais le jeton.
            $table->char('jeton_hache', 64)->unique();
            $table->timestamp('expire_le');
            $table->timestamp('acceptee_le')->nullable();
            $table->timestamp('refusee_le')->nullable();
            // Une autre demande a été acceptée, ou l'auteur l'a retirée.
            $table->timestamp('annulee_le')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expire_le']);
        });

        // Une seule demande en attente par société et par compte.
        DB::statement('CREATE UNIQUE INDEX demandes_rattachement_une_en_attente ON demandes_rattachement (societe_id, user_id) WHERE acceptee_le IS NULL AND refusee_le IS NULL AND annulee_le IS NULL');

        DeclencheursDeProtection::sansSuppression('demandes_rattachement');
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes_rattachement');
        DeclencheursDeProtection::retirer('demandes_rattachement');
    }
};
