<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les demandes déjà traitées (R3, voir ActionsUniques) : le jeton unique
 * qu'une émission, une vente à la caisse, un paiement, un avoir, un envoi,
 * une fermeture de caisse, une clôture du jour ou une validation d'achat
 * a porté, l'empreinte de son contenu et son résultat. Une demande répétée
 * avec le même jeton renvoie ce résultat sans rien refaire. Table
 * technique sans valeur fiscale : chaque ligne s'efface 30 jours après son
 * inscription (voir app:purger-actions-traitees).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actions_traitees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->uuid('jeton');
            // L'action qui a consommé le jeton (voir les constantes
            // d'ActionsUniques) et la personne qui l'a demandée : un même
            // jeton n'en sert jamais une autre, ni à un autre compte.
            $table->string('action', 40);
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            // SHA-256 des paramètres de la demande, sous leur forme
            // canonique (voir ActionsUniques::empreinte()).
            $table->char('empreinte_demande', 64);
            // Le document ou l'opération enregistré, sous son alias
            // polymorphe ; aucun quand la demande n'a rien eu à faire (une
            // clôture sans journée en attente).
            $table->string('resultat_type', 40)->nullable();
            $table->unsignedBigInteger('resultat_id')->nullable();
            $table->timestamp('created_at');

            $table->unique(['magasin_id', 'jeton']);
            $table->index(['magasin_id', 'created_at']);
            $table->index(['magasin_id', 'user_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actions_traitees');
    }
};
