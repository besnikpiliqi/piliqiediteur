<?php

namespace App\Http\Controllers;

use App\Domain\Demonstration\Actions\OuvrirLaDemonstrationAction;
use App\Domain\Demonstration\Contracts\SiteDeDemonstration;
use App\Domain\Demonstration\Exceptions\MessagesNonSignables;
use App\Http\Middleware\AjouterLesEnTetesDeSecurite;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * La page du billet d'entrée (lot DEMO-COPIE, B.4) : le bouton « Ouvrir ma
 * démonstration » de la carte y mène, dans un nouvel onglet, par un
 * formulaire du site réel (POST, jeton CSRF) ; la page porte le billet
 * signé (voir OuvrirLaDemonstrationAction) dans un formulaire qui part
 * seul vers POST /entrer-par-le-compte du site de démonstration, jamais
 * dans une adresse.
 *
 * C'est le seul formulaire du logiciel qui parte vers un autre site : sa
 * page pose sa propre politique de contenu, la politique commune avec
 * form-action 'self' et l'origine exacte du site de démonstration, que
 * l'intergiciel des en-têtes garde ; aucune autre page n'élargit
 * form-action. Elle porte aussi Referrer-Policy: no-referrer et
 * Cache-Control: no-store. Un refus (pas le propriétaire, pas de
 * démonstration, trop d'ouvertures) montre seulement son message.
 */
final class OuvrirMaDemonstrationController
{
    public function __invoke(Request $request, OuvrirLaDemonstrationAction $ouvrir, SiteDeDemonstration $site): Response
    {
        $compte = $request->user();

        if (! $compte instanceof User) {
            abort(404);
        }

        try {
            $billet = $ouvrir->handle($compte);
        } catch (AuthorizationException|LogicException|MessagesNonSignables $refus) {
            return $this->page(null, null, $refus->getMessage(), $refus instanceof AuthorizationException ? 403 : 422, null);
        }

        $origine = (string) $site->origine();

        return $this->page($origine.'/entrer-par-le-compte', $billet, null, 200, $origine);
    }

    /**
     * La politique de contenu de cette seule page : la commune, où
     * form-action admet aussi l'origine exacte du site de démonstration.
     */
    public static function politiqueDeContenu(string $origine): string
    {
        return str_replace("form-action 'self'", "form-action 'self' ".$origine, AjouterLesEnTetesDeSecurite::POLITIQUE_DE_CONTENU);
    }

    /**
     * @param  array{message: string, signature: string}|null  $billet
     */
    private function page(?string $action, ?array $billet, ?string $message, int $statut, ?string $origine): Response
    {
        $reponse = response()->view('demonstration.billet', ['action' => $action, 'billet' => $billet, 'message' => $message], $statut);
        $reponse->headers->set('Referrer-Policy', 'no-referrer');
        $reponse->headers->set('Cache-Control', 'no-store, private');

        if ($origine !== null) {
            $reponse->headers->set('Content-Security-Policy', self::politiqueDeContenu($origine));
        }

        return $reponse;
    }
}
