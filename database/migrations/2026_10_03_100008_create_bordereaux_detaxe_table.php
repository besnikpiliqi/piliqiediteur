<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les n° des bordereaux de vente à l'exportation (BVE) notés sur les
 * tickets d'un voyageur résidant hors de l'Union (I3, voir
 * NoterBordereauDetaxeAction) : le ticket reste à la TVA française, et sa
 * TVA ne se régularise qu'après le visa du bordereau. On n'y fait
 * qu'ajouter : corriger une faute de saisie note un nouveau n°, et le
 * dernier noté fait foi (voir Facture::bordereauDetaxe()). La base refuse
 * toute modification, toute suppression et tout vidage (voir
 * DeclencheursDeProtection), et les clés étrangères toute suppression d'un
 * magasin, d'un ticket ou d'un membre qu'un bordereau désigne.
 *
 * note_par : le membre qui a noté le n°.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bordereaux_detaxe', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            $table->foreignId('facture_id')->constrained('factures')->restrictOnDelete();
            $table->string('numero', 30);
            $table->foreignId('note_par')->nullable()->constrained('users')->restrictOnDelete();
            // Le nom de cette personne au moment de l'opération (voir
            // FigeLeNomDeSesAuteurs) : l'historique ne le relit jamais sur
            // son compte.
            $table->string('note_par_nom')->nullable();
            $table->timestamp('created_at');

            $table->index(['magasin_id', 'facture_id']);
        });

        DeclencheursDeProtection::ajoutSeul('bordereaux_detaxe');
    }

    public function down(): void
    {
        Schema::dropIfExists('bordereaux_detaxe');
        DeclencheursDeProtection::retirer('bordereaux_detaxe');
    }
};
