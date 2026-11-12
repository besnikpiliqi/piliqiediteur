<?php

namespace App\Filament\Concerns;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Fichiers\EnTetesDesFichiers;
use App\Domain\Fichiers\Exceptions\PieceBloqueeException;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Http\Controllers\TelechargerUnePieceDuFournisseurController;
use App\Integrations\Antivirus\AntivirusInjoignableException;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Le téléchargement d'une pièce du fournisseur d'un clic sur son nom, dans
 * la fiche de son document, facture d'achat ou avoir fournisseur (voir
 * JointLesPiecesDuFournisseur::sectionDesPieces()), sans quitter la fiche :
 * la pièce passe par PiecesDuFournisseur::contenuServable(), comme par son
 * adresse (voir TelechargerUnePieceDuFournisseurController), et le
 * navigateur l'enregistre sous son nom réécrit avec l'extension de son
 * type, jamais ne l'affiche. Une pièce bloquée, ou que l'antivirus
 * reconnaît alors comme une menace, ou un antivirus qui ne répond pas pour
 * une analyse due, ne se télécharge pas : un avis dit pourquoi sur la
 * fiche, qui montre ensuite la pièce bloquée comme telle. Seule une pièce
 * du document de la fiche se sert, à qui peut voir ce document.
 */
trait TelechargeLesPiecesDuFournisseur
{
    public const PIECE_INTROUVABLE = 'Cette pièce n\'est pas jointe à ce document.';

    public function telechargerUnePiece(string $piece): ?StreamedResponse
    {
        $document = $this->getRecord();
        $trouvee = $document instanceof FactureAchat || $document instanceof AvoirFournisseur ? $document->pieces()->parUuid($piece)->first() : null;

        if ($trouvee === null) {
            return $this->refuserLeTelechargement(self::PIECE_INTROUVABLE);
        }

        Gate::authorize('view', $document);

        try {
            $contenu = app(PiecesDuFournisseur::class)->contenuServable(CompteConnecte::exiger(), $trouvee);
        } catch (PieceBloqueeException $bloquee) {
            return $this->refuserLeTelechargement($bloquee->getMessage());
        } catch (AntivirusInjoignableException $injoignable) {
            report($injoignable);

            return $this->refuserLeTelechargement(TelechargerUnePieceDuFournisseurController::ANTIVIRUS_INJOIGNABLE);
        }

        return response()->streamDownload(function () use ($contenu): void {
            echo $contenu;
        }, $trouvee->nom, EnTetesDesFichiers::fichierRecu($trouvee->nom, $trouvee->type_mime));
    }

    private function refuserLeTelechargement(string $raison): null
    {
        Notification::make()
            ->title('Cette pièce ne se télécharge pas')
            ->body($raison)
            ->danger()
            ->persistent()
            ->send();

        return null;
    }
}
