<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\Actions\RecevoirResultatIdentiteAction;
use App\Domain\Tenancy\Models\Societe;
use App\Filament\Societe\Pages\VerificationSociete;
use App\Integrations\Identite\Contracts\VerificationIdentite;
use App\Integrations\Identite\IdentiteIndisponibleException;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Le retour du propriétaire depuis le prestataire de vérification
 * d'identité (voir DemarrerVerificationIdentiteAction et
 * VerificationSociete::verifierMonIdentite()) : la session ne sert qu'à
 * la session PILIQI qui l'a ouverte, au compte qui l'a ouverte, et une
 * seule fois. Sa référence et son state doivent être ceux que cette
 * session a gardés ; ils en sont retirés aussitôt, et le résultat se lit
 * chez le prestataire (voir RecevoirResultatIdentiteAction). Autrement :
 * « Cette vérification d'identité n'a pas été ouverte ici. », et rien
 * n'est écrit.
 */
class RetourVerificationIdentiteController
{
    public const CLE_DE_SESSION = 'verification_identite';

    public const MESSAGE_SESSION_ETRANGERE = 'Cette vérification d\'identité n\'a pas été ouverte ici.';

    public function __invoke(Request $requete, VerificationIdentite $identite, RecevoirResultatIdentiteAction $recevoir): RedirectResponse
    {
        $attendue = $requete->session()->pull(self::CLE_DE_SESSION);
        $reference = (string) $requete->query('reference');
        $state = (string) $requete->query('state');
        $societe = is_array($attendue) ? Societe::query()->find($attendue['societe_id']) : null;

        if ($societe === null
            || (int) $attendue['user_id'] !== (int) $requete->user()->getKey()
            || ! hash_equals($attendue['reference'], $reference)
            || ! hash_equals($attendue['state'], $state)) {
            Notification::make()->title(self::MESSAGE_SESSION_ETRANGERE)->danger()->send();

            return redirect()->to($societe === null ? '/' : VerificationSociete::getUrl(panel: 'societe', tenant: $societe));
        }

        try {
            $ligne = $recevoir->handle($reference, $identite->resultat($reference, $state));
            Notification::make()->title($ligne === null ? 'La vérification de votre identité est toujours en cours.' : 'Le résultat de la vérification de votre identité est enregistré.')->success()->send();
        } catch (IdentiteIndisponibleException $indisponible) {
            Notification::make()->title($indisponible->getMessage())->danger()->send();
        }

        return redirect()->to(VerificationSociete::getUrl(panel: 'societe', tenant: $societe))->header('Referrer-Policy', 'no-referrer');
    }
}
