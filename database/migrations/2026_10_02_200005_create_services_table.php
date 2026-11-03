<?php

use App\Domain\Catalogue\Models\Service;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les services (main-d'œuvre, réparations...) que le magasin a déjà
 * réalisés : la caisse les propose en cases à cocher (voir Caisse). Un nom
 * et, s'il est fixé, un prix de référence TTC avec son taux de TVA, que
 * prend toute ligne de ce service vendue par une personne qui n'est pas
 * autorisée à fixer le prix d'un service (R2, voir DerogationsDePrix).
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

            // Le prix de référence TTC et son taux, fixés ensemble par une
            // personne autorisée à fixer le prix d'un service (voir
            // ServiceResource et TrouverOuCreerServiceAction), ou tous deux
            // vides (voir Service) : sans prix de référence, seule une
            // personne autorisée vend ce service, au prix qu'elle saisit.
            $table->decimal('prix_ttc', 12, 2)->nullable();
            $table->decimal('taux_tva', 5, 2)->nullable();

            // Des frais qui accompagnent la livraison de biens (port,
            // emballage, assurance) : ils suivent la nature de la vente, et
            // une livraison hors de France peut les porter (voir
            // ControleNatureOperation).
            $table->boolean('frais_accessoires')->default(false);
            // Un service dont la loi fixe le lieu ailleurs que chez le client
            // (immeuble, transport de personnes, location courte de
            // véhicule, restauration, accès à un événement, art. 259 A du
            // CGI) : jamais facturé sans TVA française à un professionnel
            // étranger (voir ControleNatureOperation).
            $table->boolean('lieu_specifique')->default(false);

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
