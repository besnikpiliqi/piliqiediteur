<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un compte n'appartient qu'à une seule société (voir User) : son
 * propriétaire, un administrateur, un expert-comptable ou un membre (voir
 * RoleSociete). Un compte ne s'efface pas : retiré, il garde son adresse,
 * qui ne sert jamais à un autre compte (voir StatutCompte).
 *
 * Un compte créé par la société (voir CreerMembreAction,
 * CreerAdministrateurAction et CreerExpertComptableAction) reçoit un mot
 * de passe provisoire, remis de la main à la main, valable 72 heures, que
 * la personne change à sa première connexion. Elle peut refuser ce compte
 * tant que son adresse n'a jamais été confirmée, c'est-à-dire tant que
 * personne n'a suivi un lien envoyé à cette adresse (voir
 * User::peutRefuserSonCompte() et RefuserSonCompteAction). Une nouvelle
 * adresse de connexion attend la confirmation du lien envoyé à cette
 * adresse (voir ModifierProfil et ConfirmerNouvelleAdresseAction).
 *
 * Les gardes de la base qui lient le rôle de société aux affectations et
 * aux autorisations de société se posent avec la table des autorisations
 * de société, la dernière qu'elles lisent (voir GardesDesDroits).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            // proprietaire, administrateur, expert_comptable ou membre (voir
            // RoleSociete).
            $table->string('role_societe', 20)->default('membre');
            $table->string('statut', 20)->default('actif');
            // Qui a donné au compte son statut actuel (voir
            // ChangerStatutCompteAction) : un compte suspendu ou retiré par
            // le propriétaire ne se réintègre que par lui.
            $table->unsignedBigInteger('statut_change_par')->nullable();
            $table->string('name');
            // En minuscules, sans espaces autour (voir
            // User::normaliserEmail()) : unique dans tout le logiciel.
            $table->string('email')->unique();
            // La date à laquelle quelqu'un a suivi un lien envoyé à
            // l'adresse actuelle du compte (inscription, « Mot de passe
            // oublié », rattachement, confirmation d'une nouvelle adresse),
            // et rien d'autre : nulle, le compte créé par la société peut
            // encore être refusé (voir User::aConfirmeSonAdresse()).
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            // La qualité de représentant légal ou de mandataire de la
            // société, prouvée pour cette personne-ci (voir
            // ValiderLaSocieteAction et User::aSaQualiteVerifiee()) ; la
            // vérification qui l'a prouvée la nomme pour bénéficiaire.
            $table->timestamp('qualite_verifiee_le')->nullable();
            // Le mot de passe est provisoire : la personne le change avant
            // toute autre page (voir ExigerUnMotDePasseDefinitif) ; il ne
            // sert plus après son expiration.
            $table->boolean('mot_de_passe_a_changer')->default(false);
            $table->timestamp('mot_de_passe_provisoire_expire_le')->nullable();
            // Le compte qui a créé ce compte pour la personne (propriétaire,
            // responsable ou administrateur autorisé), prévenu si elle le
            // refuse, et seul à lui donner de nouveaux droits tant que son
            // mot de passe est un provisoire ; nul pour le propriétaire
            // inscrit par son lien ; il ne change plus ensuite.
            $table->foreignId('cree_par')->nullable()->constrained('users')->restrictOnDelete();
            // La nouvelle adresse de connexion demandée dans le profil, en
            // attente de la confirmation du lien envoyé à cette adresse
            // (24 heures) : SHA-256 du jeton du lien, jamais le jeton.
            $table->string('nouvelle_adresse')->nullable();
            $table->char('nouvelle_adresse_jeton_hache', 64)->nullable()->unique();
            $table->timestamp('nouvelle_adresse_expire_le')->nullable();
            $table->rememberToken();
            $table->timestamps();

            // La cible des clés étrangères composites (societe_id,
            // user_id) : une ligne n'y désigne jamais le compte d'une autre
            // société.
            $table->foreign('statut_change_par')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['societe_id', 'id']);
            $table->index(['societe_id', 'role_societe']);
        });

        // Un seul propriétaire par société.
        DB::statement("CREATE UNIQUE INDEX users_un_proprietaire_par_societe ON users (societe_id) WHERE role_societe = 'proprietaire'");

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
