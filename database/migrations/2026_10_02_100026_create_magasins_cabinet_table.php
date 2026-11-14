<?php

use App\Domain\Cabinets\Support\GardesDesCabinets;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les magasins réels qu'un client ouvre à son cabinet lié quand il ne lui
 * ouvre pas tous ses magasins, présents et futurs (voir
 * liens_cabinet.tous_les_magasins, LienCabinet::magasinsChoisis() et
 * choisirLesMagasins()), dans la société du client (décision de l'éditeur
 * du 11/11/2026). Elles s'effacent et se réécrivent quand le client règle
 * son lien ; leur historique est au journal des événements de chaque
 * magasin ajouté ou retiré (JET 130) et au journal d'audit. Les clés
 * étrangères composites gardent chaque ligne dans la société de son lien
 * et de son magasin ; la base refuse le magasin de démonstration, une
 * ligne sur un lien qui n'est pas actif ou qui ouvre tous les magasins, et,
 * à la validation de la transaction, un lien actif qui n'ouvre ni tous les
 * magasins ni aucun (voir GardesDesCabinets).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('magasins_cabinet', function (Blueprint $table) {
            // Le client.
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->foreignId('lien_id');
            $table->foreignId('magasin_id');

            $table->primary(['lien_id', 'magasin_id']);
            $table->index(['societe_id', 'magasin_id']);
            $table->foreign(['societe_id', 'lien_id'])->references(['societe_id', 'id'])->on('liens_cabinet')->restrictOnDelete();
            $table->foreign(['societe_id', 'magasin_id'])->references(['societe_id', 'id'])->on('magasins')->restrictOnDelete();
        });

        GardesDesCabinets::poser(GardesDesCabinets::MAGASINS);
        GardesDesCabinets::poserLaSelectionDesMagasins();
    }

    public function down(): void
    {
        GardesDesCabinets::retirerLaSelectionDesMagasins();
        GardesDesCabinets::retirer(GardesDesCabinets::MAGASINS);
        Schema::dropIfExists('magasins_cabinet');
    }
};
