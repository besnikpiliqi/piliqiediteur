<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use App\Domain\Charges\Support\GardesDesCharges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La pièce d'une charge (décision de l'éditeur du 14/11/2026, lot
 * CHARGES ; voir PieceDeCharge) : la quittance, la facture ou le ticket,
 * en PDF ou en photo (JPEG, PNG), une seule par charge, contrôlée comme
 * une pièce du fournisseur (voir ControleDesFichiers). Le fichier se range
 * sur le disque privé « pieces_de_charge », dans le dossier de sa société,
 * sous un nom tiré par l'application (voir PiecesDesCharges) ; la ligne
 * garde son nom réécrit avec l'extension de son type, son type lu sur le
 * contenu, sa taille, son empreinte SHA-256, qui ne se modifient jamais,
 * et l'instant de sa dernière analyse par l'antivirus, avec, s'il l'a
 * reconnue plus tard comme une menace, l'instant de son blocage et la
 * menace. Elle se remplace tant que sa charge n'est pas validée ; ensuite
 * elle se conserve comme la charge (voir GardesDesCharges).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pieces_de_charge', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            $table->unsignedBigInteger('charge_id')->unique();
            $table->string('nom');
            $table->string('type_mime', 30);
            $table->unsignedInteger('taille');
            $table->string('empreinte', 64);
            // societe-{id}/{nom tiré}.{extension de son type}
            $table->string('chemin');
            $table->timestamp('analysee_le');
            $table->timestamp('bloquee_le')->nullable();
            $table->string('menace')->nullable();
            $table->foreignId('ajoutee_par')->constrained('users')->restrictOnDelete();
            $table->string('ajoutee_par_nom');
            $table->timestamps();

            $table->foreign(['societe_id', 'charge_id'])->references(['societe_id', 'id'])->on('charges')->restrictOnDelete();
        });

        DeclencheursDeProtection::auMoins('pieces_de_charge', 'taille', 0);

        GardesDesCharges::poserSurLesPieces();
    }

    public function down(): void
    {
        GardesDesCharges::retirerDesPieces();
        Schema::dropIfExists('pieces_de_charge');
    }
};
