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
            // déterminer quelles ventes sont incluses : chaque fermeture
            // note explicitement les ventes qu'elle a comptées (voir
            // clotures_caisse_personnelles_factures).
            $table->timestamp('periode_debut');
            $table->timestamp('cloturee_a');
            $table->unsignedInteger('nombre_ventes')->default(0);
            $table->decimal('total_ht', 12, 2)->default(0);
            $table->decimal('total_tva', 12, 2)->default(0);
            $table->decimal('total_ttc', 12, 2)->default(0);
            $table->timestamps();
            // Pas de numéro, pas de statut : simple relevé personnel de
            // caisse, sans existence fiscale -- ne remplace jamais, et ne
            // bloque jamais, la clôture officielle de la journée du
            // magasin (voir ClotureCaisse).

            // Nom explicite : celui que Laravel dériverait dépasse les 64
            // caractères qu'accepte MySQL.
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
    }

    public function down(): void
    {
        Schema::dropIfExists('clotures_caisse_personnelles_factures');
        Schema::dropIfExists('clotures_caisse_personnelles');
    }
};
