<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use App\Domain\Facturation\Support\GardesDeLaVente;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le registre fiscal (art. 286 I-3° bis du CGI, voir RegistreFiscal) : on
 * n'y fait qu'ajouter. Chaque ligne garde le contenu exact qu'elle signe
 * et la signature de la ligne précédente de sa chaîne ; la base refuse
 * toute modification, toute suppression et tout vidage (voir
 * DeclencheursDeProtection), et l'enregistrement d'un ticket, d'une
 * facture ou d'un paiement au nom du propriétaire, qui ne vend jamais
 * (voir GardesDeLaVente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enregistrements_fiscaux', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            // Une valeur de ChaineFiscale : vente, encaissement, evenement.
            $table->string('chaine', 20);
            // Continue depuis 1 pour chaque magasin et chaque chaîne,
            // jamais remise à zéro.
            $table->unsignedBigInteger('sequence');
            // La nature de l'enregistrement dans sa chaîne ; pour la chaîne
            // des événements, le code du JET (voir CodeEvenement).
            $table->string('type', 30);
            // Le document enregistré, sous l'alias de Relation::morphMap()
            // (voir AppServiceProvider).
            $table->string('document_type', 40)->nullable();
            $table->unsignedBigInteger('document_id')->nullable();
            // Null : le système, sans utilisateur connecté.
            $table->foreignId('operateur_id')->nullable()->constrained('users')->restrictOnDelete();
            // En UTC à la seconde (voir HorodatageUtc).
            $table->dateTime('horodatage');
            // Le JSON exact qui est signé, relu tel quel pour vérifier la
            // signature : un texte, jamais une colonne jsonb, qui réordonne
            // les clés et retire les espaces.
            $table->longText('contenu');
            // Null pour le premier enregistrement de la chaîne.
            $table->string('signature_precedente', 200)->nullable();
            $table->string('signature', 200);
            $table->string('algorithme', 10);
            $table->char('cle_id', 64);
            $table->string('version_module', 10);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['magasin_id', 'chaine', 'sequence']);
            $table->index(['magasin_id', 'chaine', 'horodatage']);
            $table->index(['magasin_id', 'chaine', 'type']);
            // Les enregistrements d'un document, dans sa chaîne et sous ses
            // types : la vérification de l'intégrité les cherche pour
            // chaque document. Faute de statistiques, SQLite prendrait
            // sinon l'index précédent, et parcourrait chaque fois tous les
            // enregistrements du même type (tous les paiements, par
            // exemple). Nommé : le nom dérivé par Laravel dépasserait les 63
            // caractères d'un identifiant PostgreSQL.
            $table->index(['document_type', 'document_id', 'chaine', 'type'], 'enregistrements_fiscaux_document_index');
        });

        DeclencheursDeProtection::ajoutSeul('enregistrements_fiscaux');
        GardesDeLaVente::poser('enregistrements_fiscaux');
    }

    public function down(): void
    {
        Schema::dropIfExists('enregistrements_fiscaux');
        DeclencheursDeProtection::retirer('enregistrements_fiscaux');
        GardesDeLaVente::retirer('enregistrements_fiscaux');
    }
};
