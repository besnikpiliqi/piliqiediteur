<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use App\Domain\Tenancy\Support\GardesDesTypesDActivite;
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
 * de ses magasins est un de ses établissements, un SIRET. Son type
 * d'activité, choisi à son inscription, ne change jamais (voir
 * TypeDActivite) ; un cabinet d'expertise comptable n'a aucun magasin,
 * et sa vérification propre (son code d'activité, son inscription à
 * l'Ordre) est sur sa ligne de la table cabinets (voir Cabinet et
 * GardesDesCabinets). Une société qui naît de l'invitation d'un cabinet
 * est validée sans courrier, son identité remplie depuis le registre
 * national (voir AccepterLInvitationAction).
 *
 * Un SIREN n'appartient qu'à une seule société dans tout le logiciel :
 * une inscription qui attend le code de son courrier recommandé, ou une
 * société validée, le garde (index unique partiel societes_siren_unique) ;
 * une inscription close ou une société révoquée le libère. Une société ne
 * s'efface jamais : la base refuse sa suppression et le vidage de la
 * table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('societes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('siren', 9);
            // Le type d'activité choisi à l'inscription (voir
            // TypeDActivite), sans valeur par défaut : définitif dès la
            // création, la base refuse un type inconnu et toute
            // modification (voir GardesDesTypesDActivite).
            $table->string('type_activite', 20);
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
            // Nom et qualité du représentant légal tels que lus au registre
            // national, à la validation par l'invitation d'un cabinet (voir
            // AccepterLInvitationAction) ; nulle quand le registre n'en
            // donne pas, et pour une société validée par le code de son
            // courrier, dont le destinataire garde le représentant (voir
            // CodeConfirmation).
            $table->string('representant_legal')->nullable();
            // L'assurance professionnelle, facultative dans tous les types
            // qui ont un magasin (jamais pour un cabinet d'expertise
            // comptable) : l'assureur ou le garant, son adresse, le n° de
            // contrat et la couverture géographique, imprimés dans le pied
            // des documents de vente quand l'assureur est renseigné et figés
            // dans l'émetteur signé (voir Emetteur::ligneDeLAssurance()) ;
            // un champ renseigné sans assureur est refusé (voir
            // ModifierIdentiteSocieteAction).
            $table->string('assurance_assureur', 200)->nullable();
            $table->string('assurance_adresse', 300)->nullable();
            $table->string('assurance_contrat', 100)->nullable();
            $table->string('assurance_couverture', 200)->nullable();
            // Nullable : tant qu'il n'est pas choisi dans les paramètres
            // fiscaux de la société, elle ne vend pas en franchise en base
            // (voir Societe::estEnFranchiseDeTva()).
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
            // Posée par la seule validation de la société, à la saisie du
            // code de son courrier recommandé (voir ValiderLaSocieteAction) ;
            // jamais avec close_le.
            $table->timestamp('validee_le')->nullable();
            // L'inscription close sans avoir été validée, pour toujours, et
            // pourquoi (voir MotifCloture) : aucun code saisi dans les 30
            // jours de son dernier courrier, ou refus de l'éditeur. Lecture
            // seule ; son SIREN est libéré.
            $table->timestamp('close_le')->nullable();
            $table->string('motif_cloture', 30)->nullable();
            // La fin de la conservation de ses données de vérification (son
            // registre des vérifications, ses codes de courrier, les entrées
            // de la vérification de son journal d'audit), instant en UTC
            // (cast Instant) : cinq ans après la fin de la relation avec
            // l'éditeur (Q4), posée à sa révocation (voir FinDeLaRelation),
            // nulle jusque-là ; la base ne la laisse poser que sur une
            // société révoquée ou résiliée, puis plus changer, et ses
            // déclencheurs la lisent pour effacer ces données.
            $table->dateTime('verification_conservee_jusqu_au')->nullable();
            $table->timestamps();

            $table->index('siren');
            $table->index('statut');
            // La recherche des cabinets d'expertise comptable vérifiés par
            // une société qui désigne le sien (voir RechercheDesCabinets).
            $table->index('type_activite');
        });

        // Un SIREN n'appartient qu'à une seule société : celle qui attend
        // son code ou qui est validée, tant qu'elle n'est ni close ni
        // révoquée.
        DB::statement("CREATE UNIQUE INDEX societes_siren_unique ON societes (siren) WHERE close_le IS NULL AND statut <> 'revoquee'");

        DeclencheursDeProtection::sansSuppression('societes');

        // La fin de la conservation des données de vérification ne se pose
        // que sur une société révoquée ou résiliée, dont la relation avec
        // l'éditeur a pris fin, et, une fois posée, ne s'efface ni ne se
        // change plus : personne ne la pose ni ne la rapproche pour effacer
        // plus tôt ces données, même par une requête qui contourne le
        // modèle.
        DeclencheursDeProtection::colonneFigee('societes', 'verification_conservee_jusqu_au', poseeSeulementSi: DeclencheursDeProtection::relationFinie());

        // Le type d'activité est connu et ne change jamais.
        GardesDesTypesDActivite::poser(GardesDesTypesDActivite::TYPE_FIXE);
    }

    public function down(): void
    {
        GardesDesTypesDActivite::retirer(GardesDesTypesDActivite::TYPE_FIXE);
        Schema::dropIfExists('societes');
        DeclencheursDeProtection::retirer('societes');
        DeclencheursDeProtection::retirerLaColonneFigee('societes', 'verification_conservee_jusqu_au');
    }
};
