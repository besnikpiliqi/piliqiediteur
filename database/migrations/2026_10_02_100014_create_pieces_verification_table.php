<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les pièces qu'un propriétaire dépose quand ses courriers recommandés ne
 * lui sont pas parvenus, pour que l'éditeur traite son cas à la main
 * (extrait du registre, pièce d'identité du représentant légal, voir
 * PieceVerification et DeposerUnePieceAction) : le fichier, contrôlé (voir
 * ControleDesFichiers), puis chiffré, vit sur le disque privé
 * « verification » et s'efface dès la décision de l'éditeur, au plus tard
 * trente jours après le dépôt (chemin remis à null, effacee_le posée) ; la
 * ligne reste, trace de la pièce examinée. Elle ne s'efface jamais.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pieces_verification', function (Blueprint $table) {
            $table->id();
            $table->foreignId('societe_id')->constrained('societes')->restrictOnDelete();
            // TypePiece.
            $table->string('type', 30);
            // Le nom du fichier, réécrit avec l'extension de son type réel
            // (voir NomDeFichier), son type lu sur le contenu et sa taille.
            $table->string('nom');
            $table->string('type_mime', 30);
            $table->unsignedInteger('taille');
            $table->string('chemin')->nullable();
            // SHA-256 du fichier conservé (une photo se réenregistre, voir
            // ControleDesFichiers), avant son chiffrement.
            $table->char('empreinte', 64);
            // La dernière analyse du fichier par l'antivirus, refaite à la
            // consultation de l'éditeur quand elle date de plus de 24 heures,
            // et, s'il l'a reconnu plus tard comme une menace, son blocage
            // (voir PiecesDeVerification).
            $table->timestamp('analysee_le');
            $table->timestamp('bloquee_le')->nullable();
            $table->string('menace')->nullable();
            $table->foreignId('deposee_par')->constrained('users')->restrictOnDelete();
            // Le nom de cette personne au moment de l'opération (voir
            // FigeLeNomDeSesAuteurs) : l'historique ne le relit jamais sur
            // son compte.
            $table->string('deposee_par_nom');
            $table->timestamp('deposee_le');
            $table->timestamp('effacee_le')->nullable();
            $table->timestamps();

            $table->index(['societe_id', 'effacee_le']);
        });

        DeclencheursDeProtection::auMoins('pieces_verification', 'taille', 0);

        DeclencheursDeProtection::sansSuppression('pieces_verification');
    }

    public function down(): void
    {
        Schema::dropIfExists('pieces_verification');
        DeclencheursDeProtection::retirer('pieces_verification');
    }
};
