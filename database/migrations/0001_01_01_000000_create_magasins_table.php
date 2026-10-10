<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('magasins', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('nom', 150);
            // Code court inséré dans tous les numéros de ses documents
            // (FAC-LYON1-2026-000001, voir CodeMagasin et
            // DocumentNumberingService) : définitif dès son premier document.
            $table->string('code', 10);
            // Identité légale imprimée sur les devis, factures et avoirs
            // (voir IdentiteMagasin et documents/pdf/en-tete.blade.php) :
            // incomplète, elle n'empêche que les factures aux professionnels
            // (voir Magasin::mentionsLegalesManquantes()). Le capital social,
            // facultatif, s'imprime quand il est renseigné ; l'immatriculation
            // est la mention du registre (« RCS Lyon », « RM 69 ») ou celle
            // de la dispense qui s'applique. La raison sociale, la forme
            // juridique, le capital, l'immatriculation, le n° de TVA, le
            // régime de TVA et l'option pour les débits sont communs aux
            // magasins d'un même SIREN : le magasin principal les fixe (voir
            // ReglagesCommunsDeLaSociete).
            $table->string('raison_sociale', 200)->nullable();
            $table->string('forme_juridique', 100)->nullable();
            $table->decimal('capital_social', 14, 2)->nullable();
            $table->string('siren', 9)->nullable();
            // Vrai seulement pour le magasin que l'éditeur désigne comme
            // principal de son SIREN (voir app:designer-magasin-principal),
            // null pour tous les autres : l'index unique (siren,
            // designe_principal) n'en admet qu'un par SIREN. Sans
            // désignation, le principal est le plus ancien magasin non
            // supprimé du SIREN (voir ReglagesCommunsDeLaSociete). Un
            // changement de SIREN efface la désignation.
            $table->boolean('designe_principal')->nullable();
            $table->string('siret', 14)->nullable();
            $table->string('immatriculation', 100)->nullable();
            $table->string('numero_tva', 20)->nullable();
            $table->string('adresse')->nullable();
            $table->string('complement_adresse')->nullable();
            $table->string('code_postal', 10)->nullable();
            $table->string('ville', 100)->nullable();
            $table->string('pays', 2)->default('FR');
            $table->string('email', 150)->nullable();
            $table->string('telephone', 30)->nullable();
            // Actif, suspendu ou fermé (voir StatutMagasin) : suspendu ou
            // fermé, le magasin passe en lecture seule.
            $table->string('statut', 30)->default('active');
            // Nullable : tant que le propriétaire n'a pas configuré son
            // régime (voir ParametresFiscaux), la soumission périodique
            // d'e-reporting (voir SoumettreEReportingPeriodique) ignore ce
            // magasin.
            $table->string('regime_tva', 30)->nullable();
            // Option pour les débits (CGI art. 269, 2-c) : ne concerne que
            // les prestations de services, champ du flux d'e-reporting
            // (voir CalculateurPeriodeEReporting).
            $table->boolean('option_debits_tva')->default(false);
            // Barème de prix de vente (voir BaremePrixDeVente) : chaque
            // magasin démarre avec l'exemple du logiciel, désactivé (voir
            // Magasin::booted()), qu'il active et règle s'il le veut.
            // Désactivé, il garde son barème pour le jour où il le
            // réactive.
            $table->boolean('prix_vente_automatique')->default(false);
            $table->json('bareme_prix_vente');
            // Relances des factures impayées (voir RelancerFacturesImpayees) :
            // désactivées au départ ; les délais, en jours après l'échéance,
            // démarrent avec l'exemple du logiciel (voir Magasin::booted())
            // et restent gardés quand le magasin les désactive.
            $table->boolean('relances_automatiques')->default(false);
            $table->json('delais_relance');
            // Échéance d'une facture : date d'émission + délai du client,
            // sinon ce délai (voir Magasin::delaiDePaiementPour()).
            $table->unsignedSmallInteger('delai_paiement_jours')->default(30);
            // Mentions imprimées sur les factures aux professionnels (art.
            // L441-9 du Code de commerce, voir ParametresFactures) : chaque
            // magasin démarre avec les textes du logiciel (voir
            // Magasin::booted()), qu'il règle comme il veut.
            $table->string('mention_penalites_retard', 500);
            $table->string('mention_indemnite_recouvrement', 500);
            $table->string('mention_escompte', 500);
            $table->timestamps();
            $table->softDeletes();

            // Un SIRET ne sert jamais à un autre magasin, magasins supprimés
            // compris : un magasin ne s'efface jamais de cette table (voir
            // le déclencheur ci-dessous), ses documents restent conservés et
            // son établissement reste le sien.
            $table->unique('siret');
            // Un code n'appartient qu'à un seul magasin du logiciel, magasins
            // supprimés compris : il n'est jamais repris. Unique dans tout le
            // logiciel, il l'est forcément dans la société, sans lire les
            // autres magasins de son SIREN ni dépendre d'un SIREN déclaré.
            $table->unique('code');
            // Un seul magasin désigné principal par SIREN ; l'index sert aussi
            // à lire les réglages communs des magasins d'un SIREN (voir
            // ReglagesCommunsDeLaSociete).
            $table->unique(['siren', 'designe_principal']);
            $table->index(['statut']);
        });

        // Supprimé, un magasin reste en base avec deleted_at : la base
        // refuse elle-même sa suppression définitive, même par une requête
        // qui ne passe pas par le modèle (voir aussi Magasin::forceDelete()).
        DeclencheursDeProtection::sansSuppression('magasins');
    }

    public function down(): void
    {
        Schema::dropIfExists('magasins');
    }
};
