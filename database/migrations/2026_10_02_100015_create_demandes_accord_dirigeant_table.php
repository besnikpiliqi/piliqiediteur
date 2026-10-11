<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les demandes d'accord qu'un propriétaire qui n'est pas dirigeant adresse
 * à un dirigeant inscrit au registre national des entreprises (voir
 * DemandeAccordDirigeant) : un lien haché valable sept jours, ouvert par le
 * dirigeant, qui vérifie son identité puis approuve, ou refuse, le mandat.
 * Une demande ne s'efface jamais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demandes_accord_dirigeant', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->foreignId('demande_par')->constrained('users')->restrictOnDelete();
            $table->string('email_dirigeant');
            // Le dirigeant visé, tel que le registre le donne : nom,
            // prénoms, mois et année de naissance, rôle, et la chaîne qui
            // mène à lui quand le dirigeant de la société est une personne
            // morale.
            $table->json('representant_rne');
            // SHA-256 du jeton du lien, jamais le jeton.
            $table->char('jeton_hache', 64)->unique();
            $table->timestamp('expire_le');
            $table->timestamp('approuvee_le')->nullable();
            $table->timestamp('refusee_le')->nullable();
            // La ligne qui prouve l'identité du dirigeant qui a approuvé.
            $table->foreignId('verification_identite_id')->nullable()->constrained('verifications_societe')->restrictOnDelete();
            $table->timestamps();

            $table->index(['societe_id', 'expire_le']);
        });

        DeclencheursDeProtection::sansSuppression('demandes_accord_dirigeant');
    }

    public function down(): void
    {
        Schema::dropIfExists('demandes_accord_dirigeant');
        DeclencheursDeProtection::retirer('demandes_accord_dirigeant');
    }
};
