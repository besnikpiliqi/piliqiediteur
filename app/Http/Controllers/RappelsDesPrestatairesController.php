<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\Actions\SuivreLesEnvoisAction;
use App\Integrations\EnvoiRecommande\SuiviDEnvoi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Les rappels du prestataire d'envoi recommandé (POST /rappels/recommande),
 * hors de tout panneau et sans session : signés par HMAC-SHA256 de son
 * secret (RECOMMANDE_SECRET_RAPPEL) sur « horodatage.corps », l'horodatage
 * (en-tête X-Horodatage, en secondes) à cinq minutes au plus de l'heure du
 * serveur, la signature dans l'en-tête X-Signature. Un rappel mal signé,
 * sans secret configuré, trop ancien ou rejoué plus tard répond 401 et
 * n'écrit rien, avec une ligne au journal de l'application ; un rappel
 * juste rejoué dans le délai ne refait rien, chaque référence ne se
 * traitant qu'une fois (voir SuivreLesEnvoisAction). La forme exacte des
 * rappels de La Poste est à reprendre de sa spécification, avec le
 * contrat de l'éditeur.
 */
class RappelsDesPrestatairesController
{
    public const ECART_MAXIMAL_EN_SECONDES = 300;

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
