<?php

namespace App\Http\Controllers\Demonstration;

use App\Domain\Demonstration\Etat\EtatDuSite;
use Illuminate\Http\JsonResponse;

/**
 * L'état publié du site de démonstration : GET /interne/etat (lot
 * DEMO-COPIE, C.19), sans témoin ni session, signé par la clé de l'état ;
 * le site réel le lit chaque heure. À l'étape 15, le pare-feu ne l'ouvre
 * qu'au site réel.
 */
final class EtatDuSiteController
{
    public function __invoke(EtatDuSite $etat): JsonResponse
    {
        return (new JsonResponse($etat->publier()))->header('Cache-Control', 'no-store, private');
    }
}
