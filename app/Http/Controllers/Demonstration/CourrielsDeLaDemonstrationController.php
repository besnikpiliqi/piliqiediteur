<?php

namespace App\Http\Controllers\Demonstration;

use App\Domain\Demonstration\Models\CourrielDeLaDemonstration;
use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Demonstration\Visites\BandeauDeLaDemonstration;
use App\Domain\Demonstration\Visites\SocieteDeLaCopie;
use App\Domain\Demonstration\Visites\Visite;
use App\Domain\Tenancy\Models\Societe;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * « E-mails qui seraient partis » (lot DEMO-COPIE, C.9), ouverte depuis le
 * bandeau à tout compte de la copie : seulement les e-mails de la société
 * de la copie (dans la copie d'un cabinet, ceux du cabinet), jamais ceux
 * d'une autre société de la même base. La lecture se fait hors de tout
 * contexte de société, filtrée par la société de la copie : c'est
 * l'exception que la puce « Produit » de CLAUDE.md écrit pour cette page.
 *
 * - la liste : envoyé le, par, à, objet, 50 par page, du plus récent au
 *   plus ancien ;
 * - la fiche : le HTML du message dans un cadre <iframe srcdoc> isolé
 *   (sandbox="allow-popups allow-popups-to-escape-sandbox", jamais
 *   allow-scripts ni allow-same-origin), avec <base target="_blank"> : un
 *   lien s'ouvre dans un nouvel onglet ; le texte, les pièces jointes ;
 * - une pièce jointe se télécharge en pièce jointe, jamais affichée.
 */
final class CourrielsDeLaDemonstrationController
{
    public const PAR_PAGE = 50;

    public const CADRE_ISOLE = 'allow-popups allow-popups-to-escape-sandbox';

    public const PIECE_SANS_CONTENU = 'Cette pièce jointe dépassait 10 Mo : la démonstration en garde le nom et la taille, pas le contenu.';

    /**
     * La page et son adresse se lisent dans la requête elle-même, jamais
     * par les résolveurs globaux de la pagination, que Livewire remplace le
     * temps de la requête d'un composant.
     */
    public function liste(Request $requete): Response|RedirectResponse
    {
        $societe = self::societe();

        if ($societe === null) {
            return Redirection::vers(EntrerDansLaDemonstrationController::CHEMIN_DU_CHOIX);
        }

        $courriels = self::courriels($societe)
            ->orderByDesc('envoye_le')
            ->orderByDesc('id')
            ->select(['id', 'societe_id', 'expediteur', 'destinataires', 'objet', 'envoye_le'])
            ->paginate(self::PAR_PAGE, page: max(1, $requete->integer('page', 1)))
            ->withPath(route('demonstration.courriels'));

        return response()->view('demonstration.courriels', ['bandeau' => self::bandeau(), 'courriels' => $courriels])
            ->header('Cache-Control', 'no-store, private');
    }

    public function fiche(int $courriel): Response|RedirectResponse
    {
        $societe = self::societe();

        if ($societe === null) {
            return Redirection::vers(EntrerDansLaDemonstrationController::CHEMIN_DU_CHOIX);
        }

        $trouve = self::courriels($societe)->with('compte:id,name')->findOrFail($courriel);

        return response()->view('demonstration.courriel', [
            'bandeau' => self::bandeau(),
            'courriel' => $trouve,
            'cadre' => self::CADRE_ISOLE,
            'htmlDuCadre' => self::htmlDuCadre($trouve->html),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function piece(int $courriel, int $rang): Response|RedirectResponse
    {
        $societe = self::societe();

        if ($societe === null) {
            return Redirection::vers(EntrerDansLaDemonstrationController::CHEMIN_DU_CHOIX);
        }

        $piece = self::courriels($societe)->findOrFail($courriel)->pieces_jointes[$rang] ?? abort(404);
        $contenu = $piece['contenu'] === null ? false : base64_decode($piece['contenu'], true);

        if ($contenu === false) {
            abort(404);
        }

        return response($contenu, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.addcslashes(str_replace(["\r", "\n"], '', $piece['nom']), '"\\').'"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Le HTML du message tel qu'il s'écrit dans srcdoc : un <base
     * target="_blank"> ajouté en tête, pour qu'un lien s'ouvre dans un
     * nouvel onglet (les pages de PILIQI refusent d'être encadrées).
     */
    public static function htmlDuCadre(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $base = '<base target="_blank">';

        return preg_match('/<head[^>]*>/i', $html) === 1
            ? (string) preg_replace('/<head[^>]*>/i', '$0'.$base, $html, 1)
            : $base.$html;
    }

    /**
     * La société de la copie, quand un compte de cette société est
     * connecté ; sinon null : la visite passe par « Choisissez qui vous
     * êtes ».
     */
    private static function societe(): ?Societe
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);

        if (Visite::enCours() === null) {
            abort(404);
        }

        $compte = Auth::guard('web')->user();
        $societe = SocieteDeLaCopie::trouver();

        return $compte instanceof User && $societe !== null && $compte->societe_id === $societe->getKey() ? $societe : null;
    }

    /**
     * @return Builder<CourrielDeLaDemonstration>
     */
    private static function courriels(Societe $societe): Builder
    {
        return CourrielDeLaDemonstration::query()->withoutGlobalScopes()->where('societe_id', $societe->getKey());
    }

    private static function bandeau(): BandeauDeLaDemonstration
    {
        $compte = Auth::guard('web')->user();

        return BandeauDeLaDemonstration::enCours($compte instanceof User ? $compte : null) ?? abort(404);
    }
}
