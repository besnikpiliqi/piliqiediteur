<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clotures_caisse_personnelles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            // Toujours un vendeur réel, jamais le système : contrairement
            // à ClotureCaisse, il n'y a pas de déclenchement automatique
            // ici (voir le docblock de FermerSaCaisseAction).
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            // Bornes purement informatives (affichage), pas utilisées pour
            // déterminer ce qui est compté : chaque fermeture note
            // explicitement les ventes et les paiements qu'elle a comptés
            // (voir clotures_caisse_personnelles_factures et
            // clotures_caisse_personnelles_paiements).
            $table->timestamp('periode_debut');
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
            // retient que lorsqu'elle est manuelle (voir
            // VerificateurCaissesPersonnelles).

            // Nom explicite : celui que Laravel dériverait dépasse les 63
            // caractères d'un identifiant PostgreSQL.
            $table->index(['magasin_id', 'cree_par', 'cloturee_a'], 'ccp_magasin_vendeur_cloturee_a_index');
        });

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

    public function down(): void
    {
        Schema::dropIfExists('clotures_caisse_personnelles_paiements');
        Schema::dropIfExists('clotures_caisse_personnelles_factures');
        Schema::dropIfExists('clotures_caisse_personnelles');
    }
};
