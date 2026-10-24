<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'adresse IP et le navigateur de la requête qui a fait inscrire un
 * événement technique (JET) au registre fiscal (voir TraceEvenement) :
 * rangés à part, jamais dans le contenu signé, et effacés à expire_le (1
 * an après, voir config/conservation.php et app:purger-traces). La base
 * refuse toute modification, tout vidage et toute suppression avant
 * expiration (voir DeclencheursDeProtection::effacementApresExpiration()),
 * et la clé étrangère la suppression d'un événement ou d'un magasin qu'une
 * trace désigne. Un événement inscrit par une commande ou un job, sans
 * visiteur, n'a pas de trace.
 *
 * expire_le est en UTC à la seconde (voir HorodatageUtc) : les
 * déclencheurs le comparent à l'heure UTC du serveur de base de données.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('traces_evenements', function (Blueprint $table) {
            $table->foreignId('enregistrement_fiscal_id')->primary()->constrained('enregistrements_fiscaux')->restrictOnDelete();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->string('ip', 45)->nullable();
            $table->string('agent_utilisateur', 255)->nullable();
            $table->dateTime('expire_le');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['magasin_id', 'expire_le']);
        });

        DeclencheursDeProtection::effacementApresExpiration('traces_evenements');
    }

    public function down(): void
    {
        Schema::dropIfExists('traces_evenements');
        DeclencheursDeProtection::retirer('traces_evenements');
    }
};
