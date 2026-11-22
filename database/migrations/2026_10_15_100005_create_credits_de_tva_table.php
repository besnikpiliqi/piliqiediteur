<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use App\Domain\Charges\Enums\ValideePar;
use App\Domain\Charges\Support\GardesDesCreditsDeTva;
use App\Domain\Tenancy\Enums\CadenceDeLaTva;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le crédit de TVA des déclarations que la société a faites hors de PILIQI
 * (lot TVA27, choix K2 de l'éditeur, règle du 18/11/2026 ; voir
 * CreditDeTva) : une ligne par société, cadence et période déclarée, le
 * solde de sa déclaration en centimes (négatif pour un crédit, 0 sans
 * crédit), qui l'a indiqué, et qui l'a vérifié, son comptable, avec le
 * motif de sa dernière correction. La plus récente sert d'ancre au report
 * du crédit (voir ReportDuCreditDeTva). Une ligne ne s'efface jamais ; sa
 * société, sa cadence et sa période ne changent jamais ; elle n'existe que
 * dans une société au réel normal de sa cadence, jamais dans un cabinet
 * (voir GardesDesCreditsDeTva).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credits_de_tva', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            // Une valeur de CadenceDeLaTva : celle du régime de la société
            // quand la période a été déclarée.
            $table->string('cadence', 15);
            // La période de la déclaration, par son premier mois « AAAA-MM »
            // (un trimestre commence en janvier, avril, juillet ou octobre).
            $table->char('periode', 7);
            // Le solde de la déclaration, en centimes : négatif pour un
            // crédit, 0 sans crédit ; une TVA à payer, payée avec la
            // déclaration, ne se reporte pas.
            $table->bigInteger('solde');
            $table->foreignId('saisi_par')->constrained('users')->restrictOnDelete();
            $table->string('saisi_par_nom');
            // Une valeur de ValideePar : le propriétaire, le cabinet lié ou
            // l'expert-comptable déclaré.
            $table->string('saisi_comme', 20);
            $table->timestamp('saisi_le');
            // Le comptable qui l'a vérifié (confirmé, corrigé ou saisi lui-même).
            $table->foreignId('verifie_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('verifie_par_nom')->nullable();
            $table->string('verifie_comme', 20)->nullable();
            $table->timestamp('verifie_le')->nullable();
            // Le motif de sa dernière correction par le comptable.
            $table->text('motif')->nullable();
            $table->timestamps();

            $table->unique(['societe_id', 'cadence', 'periode']);
            $table->unique(['societe_id', 'id']);
        });

        $cadences = implode(', ', array_map(static fn (CadenceDeLaTva $cadence): string => "'{$cadence->value}'", CadenceDeLaTva::cases()));
        $auteurs = implode(', ', array_map(static fn (ValideePar $par): string => "'{$par->value}'", ValideePar::cases()));
        $comptables = "'".ValideePar::Cabinet->value."', '".ValideePar::ExpertComptable->value."'";
        $mensuelle = "'".CadenceDeLaTva::Mensuelle->value."'";

        foreach ([
            'cadence_connue' => "cadence IN ({$cadences})",
            'periode_lisible' => "periode ~ '^[0-9]{4}-(0[1-9]|1[0-2])$' AND (cadence = {$mensuelle} OR substr(periode, 6, 2) IN ('01', '04', '07', '10'))",
            'solde_jamais_positif' => 'solde <= 0',
            'auteur_connu' => "saisi_comme IN ({$auteurs})",
            'verifie_par_un_comptable' => "verifie_comme IS NULL OR verifie_comme IN ({$comptables})",
            'verification_entiere' => '(verifie_le IS NULL) = (verifie_par IS NULL) AND (verifie_le IS NULL) = (verifie_comme IS NULL) AND (verifie_le IS NULL) = (verifie_par_nom IS NULL)',
            'motif_d_une_verification' => 'motif IS NULL OR verifie_le IS NOT NULL',
        ] as $nom => $condition) {
            DB::statement("ALTER TABLE credits_de_tva ADD CONSTRAINT credits_de_tva_{$nom} CHECK ({$condition})");
        }

        DeclencheursDeProtection::sansSuppression('credits_de_tva');
        GardesDesCreditsDeTva::poser();
    }

    public function down(): void
    {
        GardesDesCreditsDeTva::retirer();
        Schema::dropIfExists('credits_de_tva');
        DeclencheursDeProtection::retirer('credits_de_tva');
    }
};
