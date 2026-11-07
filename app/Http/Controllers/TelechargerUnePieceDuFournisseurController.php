<?php

namespace App\Http\Controllers;

use App\Domain\Achats\Models\PieceDuFournisseur;
use App\Domain\Achats\Services\PiecesDuFournisseur;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
 * la sert toujours. Le fichier part tel qu'il a été déposé, en pièce
 * jointe, sous son nom d'origine, avec le type lu à son dépôt, et le
 * navigateur ne le devine pas ni ne le garde en cache.
 */
class TelechargerUnePieceDuFournisseurController
{
    public function __invoke(string $tenant, string $piece): StreamedResponse
    {
        $piece = PieceDuFournisseur::query()->parUuid($piece)->first() ?? abort(404);

        Gate::authorize('view', $piece->document());

        return Storage::disk(PiecesDuFournisseur::DISQUE)->download($piece->chemin, $piece->nom_origine, [
            'Content-Type' => $piece->type_mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
