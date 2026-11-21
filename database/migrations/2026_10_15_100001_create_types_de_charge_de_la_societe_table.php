<?php

use App\Domain\Charges\Enums\NatureDeCharge;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les types de charge qu'une société ajoute à la liste de PILIQI (décision
 * de l'éditeur du 14/11/2026, lot CHARGES ; voir TypeDeChargeDeLaSociete) :
 * un nom libre, unique dans la société sans tenir compte de la casse ni
 * des accents, et la nature de ce qu'il paie (biens ou services), qui fixe
 * le mois de TVA proposé. Un type utilisé ne s'efface pas : il s'archive,
 * n'est plus proposé et reste lisible sur ses charges.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('types_de_charge_de_la_societe', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->string('nom', 80);
            // Le nom en minuscules, sans accents ni espaces répétés (voir
            // TypeDeChargeDeLaSociete::cleDuNom()) : « Ménage » et « menage »
            // sont le même type.
            $table->string('nom_cle', 80);
            $table->string('nature', 20);
            $table->timestamp('archive_le')->nullable();
            $table->foreignId('cree_par')->constrained('users')->restrictOnDelete();
            $table->string('cree_par_nom');
            $table->timestamps();

            $table->unique(['societe_id', 'id']);
            $table->unique(['societe_id', 'nom_cle']);
        });

        $natures = implode(', ', array_map(static fn (NatureDeCharge $nature): string => "'{$nature->value}'", NatureDeCharge::cases()));
        DB::statement("ALTER TABLE types_de_charge_de_la_societe ADD CONSTRAINT types_de_charge_de_la_societe_nature_connue CHECK (nature IN ({$natures}))");
    }

    public function down(): void
    {
        Schema::dropIfExists('types_de_charge_de_la_societe');
    }
};
