<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devis', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->restrictOnDelete();
            // Le type du client (TypeClient), écrit à chaque enregistrement
            // du devis et repris par la facture qui en est tirée. Un devis
            // ne fige pas son client : il imprime sa fiche telle qu'elle
            // est.
            $table->string('type_client', 20);
            // Le magasin vendait-il en franchise en base de TVA au dernier
            // enregistrement du devis (voir ContexteDeVente) ? Comme le type
            // du client, la facture qui en est tirée le reprend.
            $table->boolean('franchise_en_base')->default(false);
            // La nature de la vente proposée (NatureOperation) et, pour des
            // biens qui partent hors de France, leur livraison : mêmes
            // colonnes que la facture, qui les reprend du devis converti
            // (voir CreerFactureDepuisDevisAction).
            $table->string('nature_operation', 40)->default('vente_france');
            $table->string('livraison_adresse')->nullable();
            $table->string('livraison_complement')->nullable();
            $table->string('livraison_code_postal', 10)->nullable();
            $table->string('livraison_ville', 100)->nullable();
            $table->string('livraison_pays', 2)->nullable();
            $table->date('livraison_date')->nullable();
            $table->string('transport_par', 20)->nullable();
            // nullable tant que le devis est en brouillon : la numérotation
            // n'intervient qu'à l'envoi (voir DocumentNumberingService).
            $table->string('numero', 40)->nullable();
            $table->string('statut', 20)->default('brouillon');
            $table->timestamp('date_emission')->nullable();
            $table->date('date_validite')->nullable();
            $table->string('devise', 3)->default('EUR');
            // Imprimées dans le pied de chaque page du PDF (voir PiedDePage) :
            // 500 caractères au plus, comme les mentions du magasin, pour que
            // le pied tienne toujours au bas de la page.
            $table->string('conditions_paiement', 500)->nullable();
            $table->text('notes')->nullable();
            $table->decimal('total_ht', 12, 2)->default(0);
            $table->decimal('total_tva', 12, 2)->default(0);
            $table->decimal('total_ttc', 12, 2)->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['magasin_id', 'numero']);
            $table->index(['magasin_id', 'statut']);
            $table->index(['magasin_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devis');
    }
};
