<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les codes de confirmation envoyés par courrier recommandé à l'adresse
 * officielle d'une entreprise, ou par lettre recommandée électronique
 * qualifiée à l'un de ses dirigeants (voir CodeConfirmation et
 * CodesDeConfirmation) : seul le code haché (Hash::make) est gardé, jamais
 * le code lui-même. Un seul code actif par SIREN et par motif dans tout le
 * logiciel (index unique partiel codes_confirmation_un_actif), cinq essais,
 * usage unique, trente jours après son dépôt.
 *
 * Une ligne ne s'efface jamais : elle prouve l'envoi et la remise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('codes_confirmation', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            // Copié de la société à l'envoi : les limites se comptent par
            // SIREN, toutes sociétés confondues.
            $table->string('siren', 9);
            // MotifCode.
            $table->string('motif', 30);
            // CanalEnvoi.
            $table->string('canal', 30);
            // La dénomination et l'adresse officielle avec leur source
            // (sirene ou rne), ou le dirigeant et son adresse électronique
            // pour une lettre recommandée électronique.
            $table->json('destinataire');
            $table->string('code_hache');
            $table->string('reference_envoi', 100)->nullable();
            $table->timestamp('envoye_le')->nullable();
            // Posée quand le pli est remis, ou la lettre électronique
            // acceptée par le dirigeant visé (voir SuivreLesEnvoisAction).
            $table->timestamp('remis_le')->nullable();
            // Pli revenu à l'expéditeur : le code est annulé.
            $table->timestamp('retourne_le')->nullable();
            $table->timestamp('expire_le');
            $table->unsignedTinyInteger('tentatives')->default(0);
            $table->timestamp('utilise_le')->nullable();
            $table->timestamp('annule_le')->nullable();
            // Le propriétaire qui l'a demandé ; null pour l'éditeur.
            $table->foreignId('demande_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['siren', 'envoye_le']);
            $table->index('reference_envoi');
        });

        DB::statement('CREATE UNIQUE INDEX codes_confirmation_un_actif ON codes_confirmation (siren, motif) WHERE utilise_le IS NULL AND annule_le IS NULL');

        DeclencheursDeProtection::sansSuppression('codes_confirmation');
    }

    public function down(): void
    {
        Schema::dropIfExists('codes_confirmation');
        DeclencheursDeProtection::retirer('codes_confirmation');
    }
};
