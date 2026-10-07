<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mouvements_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->cascadeOnDelete();
            $table->foreignId('produit_id')->constrained('produits')->cascadeOnDelete();
            // vente, reception_achat, retour_fournisseur, retour_client, ajustement
            $table->string('type', 30);
            // Nombre entier d'articles, toujours positif : le sens (+/-)
            // est déterminé par `type`, jamais par le signe stocké -- évite
            // les erreurs de signe en amont et rend le ledger
            // auto-descriptif.
            $table->unsignedInteger('quantite');
            $table->nullableMorphs('source');
            $table->timestamp('survenu_le');
            $table->foreignId('cree_par')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            // Ledger immuable : pas d'updated_at, un mouvement ne se
            // modifie jamais, il se compense par un mouvement inverse.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['magasin_id', 'produit_id', 'survenu_le']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_stock');
    }
};
