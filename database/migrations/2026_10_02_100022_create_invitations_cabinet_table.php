<?php

use App\Domain\Cabinets\Support\GardesDesCabinets;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les invitations qu'un cabinet d'expertise comptable vérifié envoie à une
 * entreprise pas encore inscrite, par son SIREN et l'adresse e-mail à
 * laquelle part le lien (voir InvitationCabinet et
 * InviterUneEntrepriseAction) ; la société naît validée, sans courrier, à
 * leur acceptation (voir AccepterLInvitationAction). Seule l'empreinte du
 * jeton du lien est gardée ; une invitation close a toujours sa date de
 * fin, et la purge l'efface 48 heures après, une invitation refusée 7
 * jours après (voir PurgerInvitationsDesCabinets) : aucune table ne la
 * référence, et son adresse s'efface avec elle. Une seule invitation
 * ouverte par cabinet et par SIREN ; la base refuse une invitation d'une
 * société qui n'est pas un cabinet, et une invitation close sans sa date
 * de fin (voir GardesDesCabinets).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations_cabinet', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            // Le cabinet qui invite.
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->char('siren', 9);
            // En minuscules (voir User::normaliserEmail()) ; une donnée
            // personnelle, effacée avec l'invitation par la purge.
            $table->string('email');
            // SHA-256 du jeton du lien, jamais le jeton.
            $table->char('jeton_hache', 64)->unique();
            $table->foreignId('invitee_par');
            // Le nom de cette personne au moment de l'invitation (voir
            // FigeLeNomDeSesAuteurs).
            $table->string('invitee_par_nom');
            // EtatInvitation.
            $table->string('etat', 20);
            $table->timestamp('expire_le');
            // Posée à toute sortie de l'état ouverte.
            $table->timestamp('close_le')->nullable();
            // Le propriétaire qui a retiré l'invitation, et son nom figé.
            $table->foreignId('retiree_par')->nullable();
            $table->string('retiree_par_nom')->nullable();
            $table->timestamps();

            $table->foreign(['societe_id', 'invitee_par'])->references(['societe_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['societe_id', 'retiree_par'])->references(['societe_id', 'id'])->on('users')->restrictOnDelete();
            $table->index(['etat', 'expire_le']);
            $table->index(['siren', 'etat']);
            $table->index(['societe_id', 'siren', 'close_le']);
            $table->index('close_le');
        });

        // Une seule invitation ouverte par cabinet et par SIREN.
        DB::statement("CREATE UNIQUE INDEX invitations_cabinet_une_ouverte_par_siren ON invitations_cabinet (societe_id, siren) WHERE etat = 'ouverte'");

        // Seul un cabinet invite ; une invitation close a toujours sa date
        // de fin, une invitation ouverte n'en a pas.
        GardesDesCabinets::poser(GardesDesCabinets::INVITATIONS);
    }

    public function down(): void
    {
        GardesDesCabinets::retirer(GardesDesCabinets::INVITATIONS);
        Schema::dropIfExists('invitations_cabinet');
    }
};
