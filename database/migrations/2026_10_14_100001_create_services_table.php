<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les services (main-d'œuvre, réparations...) que le magasin a déjà
 * réalisés : la caisse les propose en cases à cocher (voir Caisse). Un
 * simple nom, sans prix : le vendeur saisit le TTC à chaque vente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            $table->string('nom', 150);
            // Clé de doublon (voir Service::normaliserNom()) : "Main
            // d'œuvre" et "main d’œuvre " sont le même service, quelle que
            // soit la collation de la base.
            $table->string('nom_normalise', 150);
            // Un service n'est jamais supprimé (des lignes de vente y
            // renvoient) : on l'archive pour le retirer de la caisse.
            $table->boolean('actif')->default(true);
            $table->timestamps();

            $table->unique(['magasin_id', 'nom_normalise']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
