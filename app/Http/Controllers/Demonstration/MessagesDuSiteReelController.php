<?php

namespace App\Http\Controllers\Demonstration;

use App\Domain\Demonstration\Exceptions\MessageRefuse;
use App\Domain\Demonstration\Messages\MessagesSignes;
use App\Domain\Demonstration\Messages\ReceptionDesMessages;
use App\Domain\Demonstration\Messages\TraitementDesMessages;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * La réception des messages signés du site réel : POST /interne/messages
 * (lot DEMO-COPIE, C.7), sans témoin ni session, hors du jeton CSRF ; à
 * l'étape 15, le pare-feu ne l'ouvre qu'au site réel. Un refus répond 422
 * avec sa cause ; un message lu, la réponse de son traitement.
 *
 * Les essais se limitent par adresse IP, dans le cache du registre
 * (C.6.11), comme ceux de /entrer : chaque message refusé compte, et au-delà
 * de REFUS_PAR_MINUTE refus dans la minute, l'adresse reçoit 429 sans que
 * rien ne soit lu. Les messages du site réel, signés et à jour, ne sont pas
 * refusés et ne comptent donc pas : une démonstration très demandée n'est
 * jamais freinée ; un 429 laisse le message dans la boîte d'envoi du site
 * réel, qui le renvoie.
 */
final class MessagesDuSiteReelController
{
    public const REFUS_PAR_MINUTE = 30;

    public const TROP_DE_REFUS = 'Trop de messages refusés depuis cette adresse : réessayez dans une minute.';

    public function __invoke(Request $request, ReceptionDesMessages $reception, TraitementDesMessages $traitement): JsonResponse
    {
        $limiteur = new RateLimiter(Cache::store('registre'));
        $cle = 'demonstration-messages:'.$request->ip();

        if ($limiteur->tooManyAttempts($cle, self::REFUS_PAR_MINUTE)) {
            return new JsonResponse(['cause' => self::TROP_DE_REFUS], 429);
        }

        $message = $request->input('message');
        $signature = $request->input('signature');

        try {
            $champs = $reception->lire(is_string($message) ? $message : '', is_string($signature) ? $signature : '', MessagesSignes::AUDIENCE_DES_MESSAGES);
        } catch (MessageRefuse $refus) {
            $limiteur->hit($cle, 60);

            return new JsonResponse(['cause' => $refus->getMessage()], 422);
        }

        [$code, $corps] = $traitement->traiter($champs);

        return new JsonResponse($corps, $code);
    }
}
