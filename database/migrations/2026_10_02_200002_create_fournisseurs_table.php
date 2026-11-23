<?php

use App\Domain\Cabinets\Support\GardesDesCabinets;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fournisseurs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->string('nom', 150);
            // SIRET (établissement précis), pas SIREN : unique dans le
            // magasin, archivés compris (voir FournisseurResource).
            $table->string('siret', 14)->nullable();
            $table->string('numero_tva', 20)->nullable();
            $table->string('adresse')->nullable();
            $table->string('complement_adresse')->nullable();
            $table->string('code_postal', 10)->nullable();
            $table->string('ville', 100)->nullable();
            $table->string('pays', 2)->default('FR');
            $table->string('telephone', 30)->nullable();
            $table->string('email', 150)->nullable();
            // Qui a créé la fiche et qui l'a modifiée, archivée ou
            // restaurée en dernier, avec leur nom figé (voir
            // FigeLeNomDeSesAuteurs) : la garde des magasins ouverts refuse
            // un compte d'un cabinet lié ou un expert-comptable de la
            // société dans un magasin que le client ne lui ouvre pas (voir
            // GardesDesCabinets::AUTEURS_DANS_UN_MAGASIN).
            $table->foreignId('cree_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('cree_par_nom')->nullable();
            $table->foreignId('modifie_par')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('modifie_par_nom')->nullable();
            $table->timestamps();
            // Un fournisseur ne se supprime pas : il s'archive (voir
            // FournisseurResource), ses factures et ses produits le gardent,
            // et il se restaure.
            $table->softDeletes();

            $table->unique(['magasin_id', 'siret']);
            $table->index(['magasin_id', 'nom']);

            $table->unique(['magasin_id', 'id']);
        });

        GardesDesCabinets::poserLesMagasinsOuverts('fournisseurs');
    }

    public function down(): void
    {
        GardesDesCabinets::retirerLesMagasinsOuverts('fournisseurs');
        Schema::dropIfExists('fournisseurs');
    }
};
