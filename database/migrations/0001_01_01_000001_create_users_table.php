<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un compte appartient pour toujours à une seule société (voir User) : son
 * propriétaire, un administrateur, un expert-comptable ou un membre (voir
 * RoleSociete). Un compte ne s'efface pas : retiré, il garde son rôle, son
 * identifiant de connexion et son historique (voir StatutCompte).
 *
 * Chaque compte a un identifiant de connexion de 8 chiffres, tiré au hasard
 * et jamais redonné (voir la table identifiants). Le propriétaire se
 * connecte aussi par son adresse e-mail, son adresse de connexion,
 * toujours confirmée par un lien envoyé à cette adresse et qu'aucun autre
 * propriétaire n'utilise ; il n'en a aucune tant qu'il n'a pas suivi le
 * lien de l'adresse choisie en saisissant le code du courrier (voir
 * SaisirCodeDeConfirmationAction), ni après la clôture de son inscription
 * (voir CloreLInscriptionAction). L'adresse de tout autre compte est
 * facultative, renseignée par la personne seule dans son profil, et n'est
 * qu'un contact (voir ModifierProfil). Un compte créé par la société (voir
 * CreerMembreAction, CreerAdministrateurAction et CreerExpertComptableAction)
 * a pour premier mot de passe son identifiant ; la personne choisit le sien
 * quand elle le veut, ou à sa première connexion pour un responsable, un
 * administrateur ou un expert-comptable (voir ExigerSonPropreMotDePasse).
 *
 * Les gardes de la base qui lient le rôle de société aux affectations, aux
 * autorisations de société et aux identifiants se posent avec la table des
 * autorisations de société, la dernière qu'elles lisent (voir
 * GardesDesDroits).
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
            // User::nouveauStatut()) ; nul pour un compte d'entraînement
            // retiré par la fermeture du magasin de démonstration.
            $table->unsignedBigInteger('statut_change_par')->nullable();
            // Ce statut a-t-il été décidé par le propriétaire de la
            // société, au moment même du geste ? Un compte suspendu ou
            // retiré par lui ne se réactive ni ne se réintègre que par le
            // propriétaire, et un compte qu'il a suspendu ne se retire de
            // la société que par le propriétaire (voir PorteeDesDroits) :
            // la décision tient même quand son auteur transmet ensuite la
            // propriété, et ne le devient jamais après coup.
            $table->boolean('statut_decide_par_le_proprietaire')->default(false);
            $table->string('name');
            // En minuscules, sans espaces autour (voir
            // User::normaliserEmail()). Pour le propriétaire, son adresse de
            // connexion active, unique parmi les propriétaires (index
            // users_adresse_de_connexion) et toujours confirmée (garde
            // users_role_coherent_avec_les_droits), ou nulle quand il n'en a
            // aucune : une adresse qui n'est pas confirmée attend dans
            // nouvelle_adresse, et la clôture d'une inscription efface celle
            // de son propriétaire ; pour tout autre compte, une adresse de
            // contact facultative, que plusieurs comptes peuvent partager,
            // jamais écrite par qui crée le compte.
            $table->string('email')->nullable();
            // La date à laquelle le propriétaire a suivi le dernier lien
            // envoyé à son adresse de connexion actuelle : celui de
            // l'inscription, de la confirmation d'une adresse, de la
            // transmission de la propriété ou du « Mot de passe oublié » ;
            // nulle sans adresse de connexion.
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            // Le mot de passe est-il encore l'identifiant du compte ? Vrai à
            // la création par la société et après un accès redonné en
            // l'absence de la personne, faux dès qu'elle choisit le sien :
            // le rappel du profil et de la liste de l'équipe le lisent sans
            // recalculer un hachage, et la base refuse un propriétaire dont
            // il est vrai.
            $table->boolean('mot_de_passe_est_l_identifiant')->default(false);
            // Le jour où cette personne a validé sa société en saisissant le
            // code du courrier recommandé envoyé au nom du représentant légal
            // (voir ValiderLaSocieteAction) : propriétaire, elle signe les
            // attestations de l'éditeur dans l'application (voir
            // User::signeLesAttestationsDansLApplication()) ; effacé quand
            // elle transmet la propriété.
            $table->timestamp('code_du_courrier_saisi_le')->nullable();
            // Le compte qui a créé ce compte pour la personne (propriétaire,
            // responsable ou administrateur autorisé) : il compte ses
            // créations du jour (voir CreationDesComptes) ; nul pour le
            // propriétaire inscrit par son lien ou devenu propriétaire par le
            // code du courrier ; il ne change plus ensuite.
            $table->foreignId('cree_par')->nullable()->constrained('users')->restrictOnDelete();
            // Sa première et sa dernière connexion réussie (voir Connexion) :
            // la première prévient le propriétaire, la dernière s'affiche à
            // la personne à sa connexion suivante.
            $table->timestamp('premiere_connexion_le')->nullable();
            $table->timestamp('derniere_connexion_le')->nullable();
            // Le dernier accès redonné au compte (voir RedonnerLAccesAction),
            // montré à la personne à sa connexion suivante : quand, par
            // qui, en sa présence ou non.
            $table->timestamp('acces_redonne_le')->nullable();
            $table->foreignId('acces_redonne_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->boolean('acces_redonne_en_presence')->nullable();
            // Vrai après un accès redonné par un autre que le propriétaire :
            // le propriétaire sera prévenu du premier mot de passe que la
            // personne choisit ensuite, moment où il redevient faux.
            $table->boolean('signaler_le_prochain_mot_de_passe')->default(false);
            // Le jeton d'écran du dernier accès redonné en présence de la
            // personne : une demande répétée avec le même jeton est sans
            // effet.
            $table->uuid('jeton_acces_redonne')->nullable();
            // L'adresse de connexion en attente de la confirmation du lien
            // envoyé à cette adresse (24 heures) : la nouvelle adresse que le
            // propriétaire a demandée dans son profil, ou celle qu'il a
            // choisie en saisissant le code du courrier (voir
            // DemanderUneNouvelleAdresseAction) ; SHA-256 du jeton du lien,
            // jamais le jeton.
            $table->string('nouvelle_adresse')->nullable();
            $table->char('nouvelle_adresse_jeton_hache', 64)->nullable()->unique();
            $table->timestamp('nouvelle_adresse_expire_le')->nullable();
            // « Se souvenir de moi », pour le seul compte du propriétaire
            // (voir FournisseurDesComptes::retrieveByToken()).
            $table->rememberToken();
            $table->timestamps();

            // La cible des clés étrangères composites (societe_id,
            // user_id) : une ligne n'y désigne jamais le compte d'une autre
            // société.
            $table->foreign('statut_change_par')->references('id')->on('users')->restrictOnDelete();
            $table->unique(['societe_id', 'id']);
            $table->index(['societe_id', 'role_societe']);
            // Les créations du jour d'un auteur (voir CreationDesComptes).
            $table->index(['cree_par', 'created_at']);
        });

        // Un seul propriétaire par société.
        DB::statement("CREATE UNIQUE INDEX users_un_proprietaire_par_societe ON users (societe_id) WHERE role_societe = 'proprietaire'");
        // L'adresse de connexion d'un propriétaire n'est celle d'aucun autre
        // propriétaire ; seules les adresses de connexion actives s'y
        // écrivent (un propriétaire qui n'en a aucune a une adresse nulle,
        // qui n'entre pas dans l'index), et l'adresse de contact d'un autre
        // compte n'est pas unique.
        DB::statement("CREATE UNIQUE INDEX users_adresse_de_connexion ON users (email) WHERE role_societe = 'proprietaire'");

        // Les liens « Mot de passe oublié », rangés par adresse : seuls les
        // propriétaires s'en servent, et leur adresse est unique parmi eux
        // (voir JetonsDuLien).
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
