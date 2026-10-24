<?php

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les pièces qu'un propriétaire dépose quand ses courriers recommandés ne
 * lui sont pas parvenus, pour que l'éditeur traite son cas à la main
 * (extrait du registre, pièce d'identité du représentant légal, voir
 * PieceVerification et DeposerUnePieceAction) : le fichier, chiffré, vit sur le disque privé
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
            $table->string('chemin')->nullable();
            // SHA-256 du fichier déposé, avant son chiffrement.
            $table->char('empreinte', 64);
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

        DeclencheursDeProtection::sansSuppression('pieces_verification');
    }

    public function down(): void
    {
        Schema::dropIfExists('pieces_verification');
        DeclencheursDeProtection::retirer('pieces_verification');
    }
};
