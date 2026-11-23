<?php

use App\Domain\Cabinets\Support\GardesDesCabinets;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les clients liés qu'un cabinet d'expertise comptable confie à chacun de
 * ses collaborateurs (voir ClientConfie et ConfierDesClientsAction), dans
 * la société du cabinet : un collaborateur ne voit et n'ouvre que ces
 * clients. Les clés étrangères composites gardent chaque ligne dans le
 * cabinet de son lien et de son compte ; la base refuse un compte qui
 * n'est pas collaborateur et un lien qui n'est pas actif (voir
 * GardesDesCabinets). Les lignes d'un lien s'effacent à sa fin, celles
 * d'un collaborateur à son retrait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients_confies', function (Blueprint $table) {
            $table->id();
            // Le cabinet.
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->foreignId('lien_id');
            // Le collaborateur.
            $table->foreignId('user_id');
            $table->foreignId('confie_par')->constrained('users')->restrictOnDelete();
            // Le nom de cette personne au moment de l'opération (voir
            // FigeLeNomDeSesAuteurs).
            $table->string('confie_par_nom');
            $table->timestamp('created_at')->nullable();

            $table->unique(['lien_id', 'user_id']);
            $table->index(['societe_id', 'user_id']);
            $table->foreign(['societe_id', 'user_id'])->references(['societe_id', 'id'])->on('users')->restrictOnDelete();
            $table->foreign(['societe_id', 'lien_id'])->references(['cabinet_id', 'id'])->on('liens_cabinet')->restrictOnDelete();
        });

        GardesDesCabinets::poser(GardesDesCabinets::CLIENTS_CONFIES);
    }

    public function down(): void
    {
        GardesDesCabinets::retirer(GardesDesCabinets::CLIENTS_CONFIES);
        Schema::dropIfExists('clients_confies');
    }
};
