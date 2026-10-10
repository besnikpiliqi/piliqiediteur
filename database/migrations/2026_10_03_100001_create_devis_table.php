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
            // nullable tant que le devis est en brouillon : la numérotation
            // n'intervient qu'à l'envoi (voir DocumentNumberingService).
            $table->string('numero', 40)->nullable();
            $table->string('statut', 20)->default('brouillon');
            $table->timestamp('date_emission')->nullable();
            $table->date('date_validite')->nullable();
            $table->string('devise', 3)->default('EUR');
            $table->text('conditions_paiement')->nullable();
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
