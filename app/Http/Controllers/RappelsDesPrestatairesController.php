<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\Actions\RecevoirResultatIdentiteAction;
use App\Domain\Tenancy\Actions\SuivreLesEnvoisAction;
use App\Integrations\EnvoiRecommande\SuiviDEnvoi;
use App\Integrations\Identite\ResultatDIdentite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Les rappels des prestataires de la vérification (POST /rappels/identite
 * et /rappels/recommande), hors de tout panneau et sans session : chacun
 * signé par HMAC-SHA256 du secret de son prestataire (IDENTITE_SECRET_RAPPEL,
 * RECOMMANDE_SECRET_RAPPEL) sur « horodatage.corps », l'horodatage (en-tête
 * X-Horodatage, en secondes) à cinq minutes au plus de l'heure du serveur,
 * la signature dans l'en-tête X-Signature. Un rappel mal signé, sans
 * secret configuré, trop ancien ou rejoué plus tard répond 401 et n'écrit
 * rien, avec une ligne au journal de l'application ; un rappel juste rejoué
 * dans le délai ne refait rien, chaque référence ne se traitant qu'une
 * fois (voir RecevoirResultatIdentiteAction et SuivreLesEnvoisAction).
 * Le rappel d'une vérification d'identité ne conclut que la session dont
 * la personne est revenue dans la session PILIQI qui l'avait ouverte (voir
 * RecevoirResultatIdentiteAction) : seul, il n'écrit rien.
 */
class RappelsDesPrestatairesController
{
    public const ECART_MAXIMAL_EN_SECONDES = 300;

    public function identite(Request $requete, RecevoirResultatIdentiteAction $recevoir): JsonResponse
    {
        if (! self::estSigne($requete, config('services.identite.secret_rappel'))) {
            return self::refus('identite');
        }

        $reference = $requete->json('reference');

        if (is_string($reference) && $reference !== '') {
            $recevoir->handle($reference, ResultatDIdentite::depuisLaReponse($requete->json()->all()), retourVerifie: false);
        }

        return response()->json(['recu' => true]);
    }

    public function recommande(Request $requete, SuivreLesEnvoisAction $suivre): JsonResponse
    {
        if (! self::estSigne($requete, config('services.recommande.secret_rappel'))) {
            return self::refus('recommande');
        }

        $reference = $requete->json('reference');

        if (is_string($reference) && $reference !== '') {
            $suivre->noter($reference, SuiviDEnvoi::depuisLeRappel($requete->json()->all()));
        }

        return response()->json(['recu' => true]);
    }

    /**
     * La signature attendue d'un corps à un horodatage.
     */
    public static function signature(string $secret, string $horodatage, string $corps): string
    {
        return hash_hmac('sha256', "{$horodatage}.{$corps}", $secret);
    }

    private static function estSigne(Request $requete, mixed $secret): bool
    {
        $horodatage = (string) $requete->header('X-Horodatage');

        return is_string($secret) && $secret !== ''
            && ctype_digit($horodatage)
            && abs(time() - (int) $horodatage) <= self::ECART_MAXIMAL_EN_SECONDES
            && hash_equals(self::signature($secret, $horodatage, $requete->getContent()), (string) $requete->header('X-Signature'));
    }

    private static function refus(string $prestataire): JsonResponse
    {
        Log::warning('Rappel de prestataire refusé : signature absente, fausse ou trop ancienne.', ['prestataire' => $prestataire]);

        return response()->json(['recu' => false], 401);
    }
}
