<?php

namespace App\Http\Controllers;

use App\Domain\Achats\Models\PieceDuFournisseur;
use App\Domain\Achats\Services\PiecesDuFournisseur;
use App\Domain\Fichiers\EnTetesDesFichiers;
use App\Domain\Fichiers\Exceptions\PieceBloqueeException;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Integrations\Antivirus\AntivirusInjoignableException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Le téléchargement d'une pièce du fournisseur (voir PieceDuFournisseur),
 * par son adresse dans le panneau du magasin (voir AppPanelProvider) :
 * /app/{magasin}/pieces-du-fournisseur/{uuid}. L'adresse n'est jamais
 * devinable (un uuid tiré au hasard) et ne suffit pas : la pièce se
 * cherche dans le magasin de l'adresse seulement (MagasinScope, posé par
 * le panneau, voir BindTenantContextFromFilamentTenant), introuvable
 * ailleurs, et ne se sert qu'à qui peut voir son document, facture
 * d'achat ou avoir fournisseur (voir FactureAchatPolicy::view() et
 * AvoirFournisseurPolicy::view()), un compte du cabinet lié compris quand
 * le client lui accorde de voir les achats ; un magasin en lecture seule
 * la sert toujours.
 *
 * La pièce passe d'abord par l'antivirus quand sa dernière analyse date
 * de plus de 24 heures (voir PiecesDuFournisseur::contenuServable()) : une
 * pièce bloquée, ou que l'antivirus reconnaît alors comme une menace, ne
 * se sert plus (403) ; un antivirus injoignable fait réessayer plus tard
 * (503). Une page en français dit pourquoi. Le fichier part tel qu'il a
 * été conservé, toujours en pièce jointe, sous son nom réécrit avec
 * l'extension de son type, avec le type lu à son dépôt (voir
 * EnTetesDesFichiers::fichierRecu()).
 */
class TelechargerUnePieceDuFournisseurController
{
    public const ANTIVIRUS_INJOIGNABLE = 'L\'antivirus ne répond pas : une pièce ne se télécharge qu\'après une analyse récente. Réessayez dans quelques minutes.';

    public function __invoke(PiecesDuFournisseur $pieces, string $tenant, string $piece): Response
    {
        $piece = PieceDuFournisseur::query()->parUuid($piece)->first() ?? abort(404);

        Gate::authorize('view', $piece->document());

        try {
            $contenu = $pieces->contenuServable(CompteConnecte::exiger(), $piece);
        } catch (PieceBloqueeException $bloquee) {
            return $this->indisponible($bloquee->getMessage(), 403);
        } catch (AntivirusInjoignableException $injoignable) {
            report($injoignable);

            return $this->indisponible(self::ANTIVIRUS_INJOIGNABLE, 503)->header('Retry-After', '300');
        }

        return response($contenu, 200, EnTetesDesFichiers::fichierRecu($piece->nom, $piece->type_mime));
    }

    private function indisponible(string $message, int $statut): Response
    {
        return response()->view('telechargements.indisponible', ['message' => $message], $statut)->header('Referrer-Policy', 'no-referrer');
    }
}
