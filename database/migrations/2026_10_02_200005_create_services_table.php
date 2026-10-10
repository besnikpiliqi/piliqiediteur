<?php

use App\Domain\Catalogue\Models\Service;
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
            $table->string('nom', Service::LONGUEUR_MAXIMALE_NOM);
            // Clé de doublon calculée en PHP (voir Service::normaliserNom()),
            // que PostgreSQL et SQLite comparent octet par octet.
            $table->string('nom_normalise', Service::LONGUEUR_MAXIMALE_CLE);

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
