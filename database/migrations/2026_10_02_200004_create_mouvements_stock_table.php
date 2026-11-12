<?php

use App\Domain\Stock\Support\GardesDuStock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mouvements_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            // Le grand livre garde ses produits, archivés compris : une fiche
            // utilisée ne s'efface jamais.
            $table->foreignId('produit_id');
            // vente, reception_achat, inventaire_ouverture,
            // retour_fournisseur, retour_client, ajustement_entree,
            // ajustement_sortie, sortie_cession, entree_annulation_cession
            // (voir TypeMouvementStock).
            $table->string('type', 30);
            // Nombre entier d'articles, toujours positif : le sens (+/-)
            // est déterminé par `type`, jamais par le signe stocké -- évite
            // les erreurs de signe en amont et rend le ledger
            // auto-descriptif.
            $table->unsignedInteger('quantite');
            // Coût unitaire HT auquel un retour fournisseur sort du stock,
            // celui de sa ligne d'achat (voir
            // StockLedgerService::retournerAuFournisseur()), auquel le
            // stock d'un inventaire d'ouverture entre (voir
            // StockLedgerService::entrerALInventaireDOuverture()), ou le
            // CMUP auquel le stock d'un magasin cédé sort (voir
            // StockLedgerService::sortirPourCession()) et rentre à
            // l'annulation de sa cession (voir
            // StockLedgerService::rentrerApresAnnulationDeCession()) ; null
            // pour les autres mouvements, au CMUP du moment.
            $table->decimal('cout_unitaire_ht', 14, 4)->nullable();
            $table->nullableMorphs('source');
            $table->timestamp('survenu_le');
            $table->foreignId('cree_par')->nullable()->constrained('users')->restrictOnDelete();
            // Le nom de cette personne au moment de l'opération (voir
            // FigeLeNomDeSesAuteurs) : l'historique ne le relit jamais sur
            // son compte.
            $table->string('cree_par_nom')->nullable();
            $table->text('note')->nullable();
            // Grand livre en ajout seul : pas d'updated_at, un mouvement ne
            // se modifie ni ne se supprime jamais, il se compense par un
            // mouvement inverse ; la base le refuse, et tient elle-même le
            // stock de chaque produit depuis ce grand livre (voir
            // GardesDuStock, à la fin de up()).
            $table->timestamp('created_at');

            $table->index(['magasin_id', 'produit_id', 'survenu_le']);

            $table->foreign(['magasin_id', 'produit_id'])->references(['magasin_id', 'id'])->on('produits')->restrictOnDelete();
        });

        GardesDuStock::poser();
    }

    public function down(): void
    {
        Schema::dropIfExists('mouvements_stock');
        GardesDuStock::retirer();
    }
};
