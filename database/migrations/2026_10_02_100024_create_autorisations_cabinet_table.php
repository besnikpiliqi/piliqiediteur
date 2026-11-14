<?php

use App\Domain\Cabinets\Support\GardesDesCabinets;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les autorisations qu'un client accorde à son cabinet lié, pour ce lien
 * (voir LienCabinet::autorisations() et accorder()), dans la société du
 * client : les consultations par défaut, et, pour un client qui a des
 * articles, celles des achats et du stock qu'il ajoute ; jamais la vente,
 * les comptes, les réglages ni la caisse. Elles s'effacent et se
 * réécrivent quand le client les règle ; leur historique est au journal
 * des événements de chaque magasin (JET 130) et au journal d'audit. La
 * base refuse une autorisation hors de la liste du cabinet, une
 * autorisation des articles chez un client qui n'en a pas, une ligne sur
 * un lien qui n'est pas actif, et, à la validation de la transaction,
 * « voir » les factures ou les clôtures de caisse sans « voir celles de
 * toute l'équipe », ou l'inverse : un cabinet les voit toutes ou aucune
 * (voir GardesDesCabinets).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autorisations_cabinet', function (Blueprint $table) {
            // Le client.
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->foreignId('lien_id');
            $table->string('autorisation', 60);

            $table->primary(['lien_id', 'autorisation']);
            $table->foreign(['societe_id', 'lien_id'])->references(['societe_id', 'id'])->on('liens_cabinet')->restrictOnDelete();
            $table->foreign('autorisation')->references('cle')->on('referentiel_autorisations_magasin')->restrictOnDelete();
        });

        GardesDesCabinets::poser(GardesDesCabinets::AUTORISATIONS);
        GardesDesCabinets::poserLesPaires();
    }

    public function down(): void
    {
        GardesDesCabinets::retirerLesPaires();
        GardesDesCabinets::retirer(GardesDesCabinets::AUTORISATIONS);
        Schema::dropIfExists('autorisations_cabinet');
    }
};
