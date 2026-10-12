<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le panier en cours de la caisse de chaque vendeur, enregistré à mesure
 * qu'il se remplit (voir PanierDeCaisse) : une vente commencée survit au
 * rechargement de la page, et ne disparaît qu'encaissée ou inscrite au
 * journal des événements. Pas un document fiscal : la ligne s'efface à
 * l'encaissement ou à l'abandon de la vente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paniers_caisse', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            // Le vendeur, qui n'a qu'un panier en cours par magasin : un
            // compte n'est jamais supprimé (voir ChangerStatutCompteAction),
            // et le panier d'un membre retiré du magasin s'abandonne à la
            // clôture de sa journée.
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            // Les lignes où un produit ou un service est choisi, par clé du
            // répéteur de la caisse, telles que le vendeur les voit.
            $table->json('lignes');
            $table->timestamps();

            $table->unique(['magasin_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paniers_caisse');
    }
};
