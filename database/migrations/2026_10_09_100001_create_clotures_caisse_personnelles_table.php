<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clotures_caisse_personnelles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            // Le vendeur dont la caisse est fermée : tout membre qui vend ou
            // encaisse. Un compte n'est jamais supprimé.
            $table->foreignId('vendeur_id')->constrained('users')->restrictOnDelete();
            // Qui l'a fermée (voir ModeFermetureCaisse) : le vendeur
            // (fermee_par, lui-même), un responsable pour lui (fermee_par),
            // ou d'office, après la clôture de la journée ou à minuit
            // (fermee_par vide). La base garde la cohérence des deux
            // colonnes (voir ci-dessous).
            $table->string('mode_fermeture', 20);
            $table->foreignId('fermee_par')->nullable()->constrained('users')->restrictOnDelete();
            // jusqu_au : la borne des opérations comptées (les ventes émises
            // jusqu'à cet instant, les encaissements reçus jusqu'à sa
            // journée), maintenant pour une fermeture par le vendeur ou un
            // responsable, la fin de la journée clôturée pour une fermeture
            // d'office ; cloturee_a, l'instant de la fermeture ;
            // periode_debut, purement informatif (affichage). Chaque
            // fermeture note explicitement les ventes et les paiements
            // qu'elle a comptés (voir clotures_caisse_personnelles_factures
            // et clotures_caisse_personnelles_paiements).
            $table->timestamp('periode_debut');
            $table->timestamp('jusqu_au');
            $table->timestamp('cloturee_a');
            // Les quatre rubriques du relevé (B18, voir
            // ClotureCaissePersonnelle) : le TTC vendu (avec son HT et sa
            // TVA), les encaissements par mode de paiement (mode => montant
            // en chaîne décimale, « 30.00 »), le crédit d'avoir imputé sur
            // les ventes et ce qu'il en reste dû.
            $table->unsignedInteger('nombre_ventes')->default(0);
            $table->decimal('total_ht', 12, 2)->default(0);
            $table->decimal('total_tva', 12, 2)->default(0);
            $table->decimal('total_ttc', 12, 2)->default(0);
            $table->json('encaissements');
            $table->decimal('credit_avoir', 12, 2)->default(0);
            $table->decimal('reste_du', 12, 2)->default(0);
            $table->timestamps();
            // Pas de numéro, pas de statut : simple relevé personnel de
            // caisse, sans existence fiscale -- ne remplace jamais la
            // clôture officielle de la journée du magasin (voir
            // ClotureCaisse), qu'une caisse personnelle encore ouverte ne
            // retient jamais.

            // Nom explicite : celui que Laravel dériverait dépasse les 63
            // caractères d'un identifiant PostgreSQL.
            $table->index(['magasin_id', 'vendeur_id', 'cloturee_a'], 'ccp_magasin_vendeur_cloturee_a_index');
        });

        $this->exigerUnAuteurSaufFermetureDOffice();

        Schema::create('clotures_caisse_personnelles_factures', function (Blueprint $table) {
            // Les ventes comptées par chaque fermeture. Clé primaire : une
            // vente n'est comptée que dans une seule fermeture -- un
            // brouillon émis après une fermeture est rattrapé par la
            // suivante (voir Facture::scopeNonFermeesParLeVendeur). Noms
            // de contraintes explicites pour la même raison que ci-dessus.
            $table->foreignId('facture_id')->primary()->constrained('factures', 'id', 'ccp_factures_facture_foreign')->cascadeOnDelete();
            $table->foreignId('cloture_caisse_personnelle_id')->constrained('clotures_caisse_personnelles', 'id', 'ccp_factures_cloture_foreign')->cascadeOnDelete();
        });

        Schema::create('clotures_caisse_personnelles_paiements', function (Blueprint $table) {
            // Les paiements comptés par chaque fermeture, contre-passations
            // comprises : un paiement n'est compté que dans une seule (voir
            // Paiement::scopeNonFermesParLeVendeur). Un paiement ne se
            // supprime jamais (voir la migration des paiements).
            $table->foreignId('paiement_id')->primary()->constrained('paiements', 'id', 'ccp_paiements_paiement_foreign')->restrictOnDelete();
            $table->foreignId('cloture_caisse_personnelle_id')->constrained('clotures_caisse_personnelles', 'id', 'ccp_paiements_cloture_foreign')->cascadeOnDelete();
        });
    }

    /**
     * Une fermeture d'office n'a pas d'auteur, toute autre en a un : sans
     * quoi le relevé ne saurait dire qui a fermé la caisse (voir
     * ClotureCaissePersonnelle::libelleDeFermeture()). Une contrainte CHECK
     * sous PostgreSQL ; SQLite n'en ajoute pas à une table créée, et la
     * même condition y est vérifiée par des déclencheurs BEFORE INSERT et
     * BEFORE UPDATE. L'une et les autres disparaissent avec la table.
     */
    private function exigerUnAuteurSaufFermetureDOffice(): void
    {
        $nom = 'ccp_auteur_selon_le_mode';

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE clotures_caisse_personnelles ADD CONSTRAINT {$nom} CHECK ((mode_fermeture = 'automatique') = (fermee_par IS NULL))");

            return;
        }

        foreach (['INSERT', 'UPDATE'] as $operation) {
            DB::unprepared("CREATE TRIGGER {$nom}_".strtolower($operation)." BEFORE {$operation} ON clotures_caisse_personnelles"
                ." WHEN (NEW.mode_fermeture = 'automatique') <> (NEW.fermee_par IS NULL)"
                ." BEGIN SELECT RAISE(ABORT, '{$nom} : une fermeture d''office n''a pas d''auteur, toute autre en a un'); END");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clotures_caisse_personnelles_paiements');
        Schema::dropIfExists('clotures_caisse_personnelles_factures');
        Schema::dropIfExists('clotures_caisse_personnelles');
    }
};
