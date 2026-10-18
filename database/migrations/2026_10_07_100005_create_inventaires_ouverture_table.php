<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventaires_ouverture', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            // Le nom de cette personne au moment de l'opération (voir
            // FigeLeNomDeSesAuteurs) : l'historique ne le relit jamais sur
            // son compte.
            $table->string('cree_par_nom')->nullable();
            $table->foreignId('valide_par')->nullable()->constrained('users')->nullOnDelete();
            $table->string('valide_par_nom')->nullable();
            // Attribué seulement à la validation (voir
            // ValiderInventaireOuvertureAction), dans la série INV du
            // magasin, comme le numéro d'une facture d'achat.
            $table->string('numero', 40)->nullable();
            $table->string('statut', 20)->default('brouillon');
            $table->timestamp('date_validation')->nullable();
            $table->text('notes')->nullable();
            // La valeur hors taxe du stock inventorié : la somme exacte, en
            // centimes, de la valeur de ses lignes (voir
            // ConstructeurLigneInventaire), jamais une somme faite par la
            // base.
            $table->decimal('valeur_ht', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['magasin_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventaires_ouverture');
    }
};
