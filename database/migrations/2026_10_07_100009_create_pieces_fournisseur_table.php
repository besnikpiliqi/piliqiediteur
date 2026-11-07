<?php

use App\Domain\Achats\Support\GardesDesPiecesDuFournisseur;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les pièces du fournisseur (décision de l'éditeur du 03/11/2026, lot EX) :
 * la vraie facture d'achat ou la vraie note d'avoir du fournisseur, en PDF
 * ou en photo (JPEG, PNG), jointe à une facture d'achat ou à un avoir
 * fournisseur du magasin (voir PieceDuFournisseur). Le fichier se range sur
 * le disque privé « pieces_fournisseur », dans le dossier de son magasin,
 * sous un nom tiré par l'application (voir PiecesDuFournisseur) ; la ligne
 * garde son nom d'origine pour l'affichage, son type lu sur le contenu, sa
 * taille et son empreinte SHA-256, et ne se modifie jamais. Une pièce ne
 * s'efface que d'une facture d'achat en brouillon : jointe à une facture
 * validée ou à un avoir émis, elle se conserve comme le document (voir
 * GardesDesPiecesDuFournisseur).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pieces_fournisseur', function (Blueprint $table) {
            $table->id();
            // L'identifiant de l'adresse de téléchargement de la pièce, tiré
            // au hasard : jamais une adresse devinable (voir
            // TelechargerUnePieceDuFournisseurController).
            $table->uuid('uuid')->unique();
            $table->foreignId('magasin_id')->constrained('magasins')->restrictOnDelete();
            // Son document, une facture d'achat ou un avoir fournisseur de
            // son magasin, jamais les deux (voir
            // GardesDesPiecesDuFournisseur).
            $table->unsignedBigInteger('facture_achat_id')->nullable();
            $table->unsignedBigInteger('avoir_fournisseur_id')->nullable();
            // Le nom du fichier déposé, réduit à son nom de base, pour
            // l'affichage et le téléchargement ; jamais un chemin.
            $table->string('nom_origine');
            // application/pdf, image/jpeg ou image/png, lu sur le contenu
            // du fichier, jamais sur son extension.
            $table->string('type_mime', 30);
            $table->unsignedInteger('taille');
            $table->string('empreinte', 64);
            // Le chemin du fichier sur le disque « pieces_fournisseur » :
            // magasin-{id}/{nom tiré}.{extension de son type}.
            $table->string('chemin');
            $table->foreignId('ajoutee_par')->nullable()->constrained('users')->restrictOnDelete();
            // Le nom de cette personne au moment du dépôt (voir
            // FigeLeNomDeSesAuteurs).
            $table->string('ajoutee_par_nom')->nullable();
            $table->timestamps();

            $table->foreign(['magasin_id', 'facture_achat_id'])->references(['magasin_id', 'id'])->on('factures_achat')->restrictOnDelete();
            $table->foreign(['magasin_id', 'avoir_fournisseur_id'])->references(['magasin_id', 'id'])->on('avoirs_fournisseur')->restrictOnDelete();
            // Un même fichier ne se joint qu'une fois à un même document.
            $table->unique(['facture_achat_id', 'empreinte']);
            $table->unique(['avoir_fournisseur_id', 'empreinte']);
            $table->index(['magasin_id', 'facture_achat_id']);
            $table->index(['magasin_id', 'avoir_fournisseur_id']);
        });

        GardesDesPiecesDuFournisseur::poser();
    }

    public function down(): void
    {
        GardesDesPiecesDuFournisseur::retirer();
        Schema::dropIfExists('pieces_fournisseur');
    }
};
