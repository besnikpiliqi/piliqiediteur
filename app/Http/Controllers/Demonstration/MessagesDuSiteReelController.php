<?php

namespace App\Http\Controllers\Demonstration;

use App\Domain\Demonstration\Exceptions\MessageRefuse;
use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Demonstration\Messages\ReceptionDesMessages;
use App\Domain\Demonstration\Messages\TraitementDesMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * La réception des messages signés du site réel : POST /interne/messages
 * (lot DEMO-COPIE, C.7), sans témoin ni session, hors du jeton CSRF ; à
 * l'étape 15, le pare-feu ne l'ouvre qu'au site réel. Un refus répond 422
 * avec sa cause ; un message lu, la réponse de son traitement.
 */
final class MessagesDuSiteReelController
{
    public function __invoke(Request $request, ReceptionDesMessages $reception, TraitementDesMessages $traitement): JsonResponse
    {
        $message = $request->input('message');
        $signature = $request->input('signature');

        try {
            $champs = $reception->lire(is_string($message) ? $message : '', is_string($signature) ? $signature : '', MessagesSignes::AUDIENCE_DES_MESSAGES);
        } catch (MessageRefuse $refus) {
            return new JsonResponse(['cause' => $refus->getMessage()], 422);
        }

        [$code, $corps] = $traitement->traiter($champs);

        return new JsonResponse($corps, $code);
    }
}
