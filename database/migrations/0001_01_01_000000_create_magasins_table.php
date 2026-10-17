<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('magasins', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->string('nom', 150);
            // Code court inséré dans tous les numéros de ses documents
            // (FAC-LYON1-2026-000001, voir CodeMagasin et
            // DocumentNumberingService) : unique dans la société, définitif
            // dès son premier document.
            $table->string('code', 10);
            // Le magasin de démonstration de la société, au plus un (voir
            // OuvrirLaDemonstrationAction) : son identité est fictive, sans
            // SIRET, et rien n'en passe aux magasins réels.
            $table->boolean('est_demonstration')->default(false);
            // L'établissement : son SIRET, que les documents émis gardent
            // signé, ne change qu'avec le registre des SIRET réservés
            // (voir SiretsDesMagasins), et son adresse, reprise du
            // répertoire Sirene. L'identité légale qu'il imprime en plus
            // est celle de sa société (voir Societe et Emetteur). Nul pour
            // le magasin de démonstration, et pour lui seul (voir
            // Magasin::booted()).
            $table->string('siret', 14)->nullable();
            // Posée au premier contrôle Sirene concluant de l'établissement
            // (voir CreerEtablissementAction) : un magasin réel dont
            // l'établissement n'est pas vérifié ne vend pas (voir
            // Magasin::peutVendre()) ; la démonstration n'a pas
            // d'établissement.
            $table->timestamp('etablissement_verifie_le')->nullable();
            $table->string('adresse')->nullable();
            $table->string('complement_adresse')->nullable();
            $table->string('code_postal', 10)->nullable();
            $table->string('ville', 100)->nullable();
            $table->string('pays', 2)->default('FR');
            // Les coordonnées et les références d'acheteur du magasin,
            // réglées dans son panneau (voir CoordonneesMagasin) : les
            // références qu'il donne à ses fournisseurs (n° de client, code
            // d'acheteur...) servent, à l'étape 2, à proposer le magasin
            // destinataire d'une facture reçue.
            $table->string('email', 150)->nullable();
            $table->string('telephone', 30)->nullable();
            $table->json('references_acheteur')->nullable();
            // L'établissement principal que la société indique, au plus un
            // (voir ChoisirEtablissementPrincipalAction) : une simple
            // information, sans aucun effet sur les réglages ni sur les
            // documents ; aucun magasin n'a de privilège sur les autres.
            $table->boolean('est_etablissement_principal')->default(false);
            // Actif ou fermé (voir StatutMagasin) : la société ferme et
            // rouvre elle-même ses magasins (voir FermerMagasinAction et
            // RouvrirMagasinAction) ; fermé, il passe en lecture seule. Le
            // motif de sa fermeture est celui de sa dernière période
            // d'activité (voir PeriodeActivite).
            $table->string('statut', 20)->default('actif');
            // La suspension décidée par l'éditeur, indépendante de la
            // fermeture : jamais levée par une réouverture (voir
            // Magasin::estEnLectureSeule()).
            $table->timestamp('suspendu_le')->nullable();
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

            // Un code n'appartient qu'à un seul magasin de la société (voir
            // CodeMagasin) : deux magasins d'une même société ne produisent
            // jamais le même numéro de document.
            $table->unique(['societe_id', 'code']);
            // Un SIRET n'appartient qu'à un seul magasin de la société ; le
            // registre des SIRET réservés le refuse en plus à tout autre
            // magasin du logiciel (voir SiretsDesMagasins).
            $table->unique(['societe_id', 'siret']);
            // La cible des clés étrangères composites (societe_id,
            // magasin_id) des tables propres à une société : une ligne n'y
            // désigne jamais le magasin d'une autre société.
            $table->unique(['societe_id', 'id']);
            $table->index('statut');
        });

        // Une seule démonstration et un seul établissement principal par
        // société.
        DB::statement('CREATE UNIQUE INDEX magasins_une_demonstration_par_societe ON magasins (societe_id) WHERE est_demonstration');
        DB::statement('CREATE UNIQUE INDEX magasins_un_principal_par_societe ON magasins (societe_id) WHERE est_etablissement_principal');

        // Un magasin ne s'efface jamais : ses documents se conservent dix
        // ans et son SIRET reste réservé. La base refuse elle-même sa
        // suppression et le vidage de la table, même par une requête qui
        // ne passe pas par le modèle (voir aussi Magasin::forceDelete()).
        DeclencheursDeProtection::sansSuppression('magasins');
    }

    public function down(): void
    {
        Schema::dropIfExists('magasins');
        DeclencheursDeProtection::retirer('magasins');
    }
};
