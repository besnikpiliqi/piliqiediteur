<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le registre des vérifications d'une société (voir VerificationSociete) :
 * chaque contrôle (répertoire Sirene, registre national des entreprises,
 * identité, mandat d'un dirigeant, code d'un courrier), chaque décision de
 * l'éditeur et chaque signal de risque, concluant, négatif, impossible, en
 * attente ou à examiner, avec ce qu'il a lu (liste blanche par type, voir
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
            $table->string('signal', 40)->nullable();
            $table->json('details')->nullable();
            $table->foreignId('auteur_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->json('operateur')->nullable();
            // La personne dont la ligne prouve l'identité ou la qualité de
            // représentant (voir EvaluateurDeVerification) ; nulle pour un
            // contrôle de l'entreprise, ou l'identité d'un dirigeant sans
            // compte qui approuve un mandat.
            $table->foreignId('beneficiaire_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('expire_le')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign(['societe_id', 'magasin_id'])->references(['societe_id', 'id'])->on('magasins')->restrictOnDelete();
            $table->index(['societe_id', 'type']);
            // Le rappel d'un prestataire retrouve sa ligne par sa référence.
            $table->index(['fournisseur', 'reference']);
        });

        DeclencheursDeProtection::effacementApresExpiration('verifications_societe');
    }

    public function down(): void
    {
        Schema::dropIfExists('verifications_societe');
        DeclencheursDeProtection::retirer('verifications_societe');
    }
};
