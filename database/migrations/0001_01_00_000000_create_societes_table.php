<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le client de l'éditeur : une entreprise, société ou entrepreneur
 * individuel, un SIREN (voir Societe). Elle porte seule l'identité légale
 * que ses magasins impriment (raison sociale, forme juridique, capital,
 * immatriculation, siège, n° de TVA) et ses réglages fiscaux communs
 * (régime de TVA, option pour les débits, mois de fin d'exercice) ; chacun
 * de ses magasins est un de ses établissements, un SIRET.
 *
 * Plusieurs sociétés du même SIREN coexistent tant qu'aucune n'est validée
 * : seule une société validée le réserve (index unique partiel
 * societes_siren_valide_unique), et une société révoquée le libère. Une
 * société ne s'efface jamais : la base refuse sa suppression et le vidage
 * de la table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('societes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('siren', 9);
            // Préremplies depuis le répertoire Sirene à l'inscription,
            // complétées dans l'administration de la société (voir
            // IdentiteSociete) : incomplètes, elles n'empêchent que les
            // factures aux professionnels (voir
            // Societe::mentionsLegalesManquantes()).
            $table->string('raison_sociale', 200)->nullable();
            $table->string('forme_juridique', 30)->nullable();
            // Le libellé exact d'une forme « autre », ou d'une variante
            // (SELARL, SA coopérative...) dont la famille fixe les règles.
            $table->string('forme_juridique_autre', 100)->nullable();
            // Pour une forme « autre » seulement : la société est-elle
            // commerciale (son capital est alors exigé) ?
            $table->boolean('societe_commerciale')->nullable();
            // Le dernier code de catégorie juridique lu dans Sirene.
            $table->string('categorie_juridique_insee', 4)->nullable();
            $table->decimal('capital_social', 14, 2)->nullable();
            $table->boolean('capital_variable')->default(false);
            // « RCS Lyon », « RM 69 », ou la mention de la dispense.
            $table->string('immatriculation', 100)->nullable();
            $table->string('numero_tva', 20)->nullable();
            // Le siège social, ou l'adresse de l'entreprise d'un
            // entrepreneur individuel.
            $table->string('siege_adresse')->nullable();
            $table->string('siege_complement_adresse')->nullable();
            $table->string('siege_code_postal', 10)->nullable();
            $table->string('siege_ville', 100)->nullable();
            // Nullable : tant qu'il n'est pas choisi, l'e-reporting
            // périodique ignore la société (voir
            // SoumettreEReportingPeriodique).
            $table->string('regime_tva', 30)->nullable();
            // Option pour les débits (CGI art. 269, 2-c) : ne concerne que
            // les prestations de services.
            $table->boolean('option_debits_tva')->default(false);
            // Verrouillé dès la première vente de l'un de ses magasins
            // (voir Societe::aVenduUneFois()).
            $table->unsignedTinyInteger('mois_fin_exercice')->default(12);
            // Active, suspendue, résiliée ou révoquée (voir
            // StatutSociete) : hors active, ses magasins sont en lecture
            // seule.
            $table->string('statut', 20)->default('active');
            // Posée par la seule validation de la société ; jamais avec
            // refusee_le.
            $table->timestamp('validee_le')->nullable();
            // Refus définitif : lecture seule pour toujours.
            $table->timestamp('refusee_le')->nullable();
            // Réclamation d'un SIREN validé par une autre société : signalée
            // une fois à l'éditeur quand elle devient possible, puis
            // ouverte par lui.
            $table->timestamp('reclamation_signalee_le')->nullable();
            $table->timestamp('reclamation_ouverte_le')->nullable();
            $table->timestamps();

            $table->index('siren');
            $table->index('statut');
        });

        // Un SIREN n'est réservé qu'à une société validée et non
        // révoquée : les inscriptions en attente du même SIREN
        // coexistent, isolées.
        DB::statement("CREATE UNIQUE INDEX societes_siren_valide_unique ON societes (siren) WHERE validee_le IS NOT NULL AND statut <> 'revoquee'");

        DeclencheursDeProtection::sansSuppression('societes');
    }

    public function down(): void
    {
        Schema::dropIfExists('societes');
        DeclencheursDeProtection::retirer('societes');
    }
};
