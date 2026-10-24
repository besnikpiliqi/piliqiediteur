<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le registre des vérifications d'une société (voir VerificationSociete) :
 * chaque contrôle du répertoire Sirene (l'entreprise, l'établissement d'un
 * magasin), chaque code de courrier recommandé saisi et chaque décision de
 * l'éditeur sur des pièces, concluant, négatif ou impossible, avec ce
 * qu'il a lu (liste blanche par type, voir
 * TypeVerification::detailsRetenus()). Une ligne ne se modifie jamais et
 * ne s'efface qu'une fois sa durée de conservation fixée et passée
 * (expire_le, nulle jusqu'à la fin de la relation) ; la base refuse tout
 * le reste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verifications_societe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->unsignedBigInteger('magasin_id')->nullable();
            // TypeVerification.
            $table->string('type', 30);
            // ResultatVerification.
            $table->string('resultat', 20);
            $table->string('fournisseur', 30)->nullable();
            $table->string('reference', 100)->nullable();
            $table->json('details')->nullable();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->restrictOnDelete();
            // Le nom de cette personne au moment de l'opération (voir
            // FigeLeNomDeSesAuteurs) : l'historique ne le relit jamais sur
            // son compte.
            $table->string('auteur_nom')->nullable();
            $table->json('operateur')->nullable();
            $table->dateTime('expire_le')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['societe_id', 'magasin_id'])->references(['societe_id', 'id'])->on('magasins')->restrictOnDelete();
            $table->index(['societe_id', 'type']);
        });

        DeclencheursDeProtection::effacementApresExpiration('verifications_societe');
    }

    public function down(): void
    {
        Schema::dropIfExists('verifications_societe');
        DeclencheursDeProtection::retirer('verifications_societe');
    }
};
