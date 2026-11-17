<?php

namespace App\Http\Controllers\Demonstration;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Une redirection des pages du site de démonstration, toujours une
 * vraie réponse HTTP : le changement de compte du bandeau suit souvent
 * une page de Filament, dont Livewire remplace le service « redirect »
 * le temps de sa requête (voir ChoisirQuiVousEtesController).
 */
final class Redirection
{
    /**
     * @param  array<string, string>  $aMontrer  ce que la page suivante lit dans la session (flash)
     */
    public static function vers(string $chemin, int $statut = 302, ?Request $requete = null, array $aMontrer = []): RedirectResponse
    {
        $reponse = new RedirectResponse(url($chemin), $statut);

        if ($requete !== null && $requete->hasSession()) {
            $reponse->setSession($requete->session());

            foreach ($aMontrer as $cle => $valeur) {
                $reponse->with($cle, $valeur);
            }
        }

        return $reponse;
    }
}
