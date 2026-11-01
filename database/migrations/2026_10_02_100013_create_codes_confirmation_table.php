<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les codes de confirmation envoyés par courrier recommandé avec avis de
 * réception, au nom du représentant légal d'une entreprise, à l'adresse
 * officielle de son siège, remis contre signature au destinataire en
 * personne (voir CodeConfirmation et EnvoyerCodeDeConfirmationAction) : seul le
 * code haché (Hash::make) est gardé, jamais le code lui-même. Un seul code
 * actif par SIREN et par motif dans tout le logiciel (index unique partiel
 * codes_confirmation_un_actif), usage unique, trente jours après son envoi
 * (expire_le) ; chaque saisie fausse se compte (tentatives).
 *
 * Une ligne prouve l'envoi et la remise : son suivi se pose, mais elle ne
 * s'efface qu'une fois passée la fin de sa conservation
 * (conserver_jusqu_au, nulle jusqu'à la fin de la relation avec la
 * société, comme le registre des vérifications) ; la base refuse tout
 * autre effacement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codes_confirmation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            // Le SIREN de l'entreprise à qui le courrier part : celui de la
            // société ; pour l'examen d'un SIREN, celui de l'entité correcte
            // (voir ExaminerSirenAction) ; pour la libération d'un SIRET,
            // celui du titulaire de ce SIRET, que forment ses neuf premiers
            // chiffres (voir LibererUnSiretSaisiParErreurAction). La page du
            // code retrouve le courrier par le SIREN saisi (voir
            // SaisirCodeDeConfirmationAction).
            $table->string('siren', 9);
            // Pour la libération d'un SIRET, le SIRET que le courrier
            // désigne : son code ne prouve la libération que de celui-ci ;
            // nul pour tout autre motif.
            $table->string('siret', 14)->nullable();
            // MotifCode.
            $table->string('motif', 30);
            // Le destinataire tel que le courrier l'imprime : le nom du
            // représentant légal (une personne morale dirigeante, à
            // l'attention de son représentant), la dénomination de
            // l'entreprise et l'adresse officielle de son siège, avec sa
            // source (rne ou sirene), lus le jour de l'envoi (voir
            // AdressePostale).
            $table->json('destinataire');
            $table->string('code_hache');
            $table->string('reference_envoi', 100)->nullable();
            $table->timestamp('envoye_le');
            // Posée quand le pli est remis contre signature (voir
            // SuivreLesEnvoisAction).
            $table->timestamp('remis_le')->nullable();
            // Pli revenu à l'expéditeur, faute d'avoir été retiré : le code
            // est annulé.
            $table->timestamp('retourne_le')->nullable();
            $table->timestamp('expire_le');
            // Les saisies fausses de ce code (voir
            // SaisirCodeDeConfirmationAction).
            $table->unsignedInteger('tentatives')->default(0);
            $table->timestamp('utilise_le')->nullable();
            // Le code d'une procédure de l'éditeur ne prouve qu'une
            // procédure (une révocation, la libération de son SIRET) : posée
            // quand elle se fait, dans sa transaction (voir
            // CodeConfirmation::preuveDeLaRemise()).
            $table->timestamp('procedure_faite_le')->nullable();
            $table->timestamp('annule_le')->nullable();
            // Le propriétaire qui l'a demandé, à la confirmation de son
            // inscription ou pour un nouvel envoi ; null pour l'éditeur.
            $table->foreignId('demande_par')->nullable()->constrained('users')->restrictOnDelete();
            // Le nom de cette personne au moment de l'opération (voir
            // FigeLeNomDeSesAuteurs) : l'historique ne le relit jamais sur
            // son compte.
            $table->string('demande_par_nom')->nullable();
            // La fin de sa conservation, instant en UTC (cast Instant) :
            // cinq ans après la fin de la relation avec la société (Q4),
            // nulle jusque-là.
            $table->dateTime('conserver_jusqu_au')->nullable();
            $table->timestamps();

            // Le dernier courrier de chaque motif d'une société (voir
            // CourriersDeVerification).
            $table->index(['societe_id', 'motif', 'envoye_le']);
            $table->index('reference_envoi');
        });

        DB::statement('CREATE UNIQUE INDEX codes_confirmation_un_actif ON codes_confirmation (siren, motif) WHERE utilise_le IS NULL AND annule_le IS NULL');

        DeclencheursDeProtection::suppressionApresConservation('codes_confirmation', 'conserver_jusqu_au');
    }

    public function down(): void
    {
        Schema::dropIfExists('codes_confirmation');
        DeclencheursDeProtection::retirer('codes_confirmation');
    }
};
