<?php

namespace App\Http\Controllers;

use App\Domain\Tenancy\Actions\ApprouverMandatAction;
use App\Domain\Tenancy\Actions\RecevoirResultatIdentiteAction;
use App\Domain\Tenancy\Exceptions\VerificationRefuseeException;
use App\Domain\Tenancy\Models\DemandeAccordDirigeant;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Integrations\Identite\Contracts\VerificationIdentite;
use App\Integrations\Identite\IdentiteIndisponibleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Le lien qu'un dirigeant reçoit pour approuver le mandat d'un propriétaire
 * (voir DemanderAccordDUnDirigeantAction et ApprouverMandatAction), sans
 * compte PILIQI : l'ouvrir (GET) n'affiche que la demande et ne change
 * rien ; « Vérifier mon identité et approuver » (POST) ouvre une session
 * chez le prestataire, liée à la session de ce navigateur par son state ;
 * le retour (GET) n'est reçu que de cette session-là, une fois ; « Refuser »
 * (POST) refuse. La demande se cherche par l'empreinte du jeton, jamais
 * par le jeton, et un lien expiré, déjà répondu ou inconnu dit seulement
 * « Ce lien n'est plus valable. ». Les pages ne transmettent pas leur
 * adresse aux liens qui en partent (Referrer-Policy: no-referrer).
 */
class AccordDirigeantController
{
    public function afficher(string $jeton): Response
    {
        return $this->page($jeton, ApprouverMandatAction::demandeEnAttente($jeton), null);
    }

    public function demarrer(string $jeton, Request $requete, ApprouverMandatAction $approuver): Response|RedirectResponse
    {
        try {
            $demarree = $approuver->demarrer($jeton, rtrim((string) config('app.url'), '/').route('accord-dirigeant.retour', ['jeton' => $jeton], absolute: false));
        } catch (VerificationRefuseeException $refus) {
            return $this->page($jeton, ApprouverMandatAction::demandeEnAttente($jeton), $refus->getMessage());
        }

        $requete->session()->put(self::cleDeSession($jeton), ['reference' => $demarree['session']->reference, 'state' => $demarree['state']]);

        return redirect()->away($demarree['session']->url)->header('Referrer-Policy', 'no-referrer');
    }

    public function retour(string $jeton, Request $requete, VerificationIdentite $identite, RecevoirResultatIdentiteAction $recevoir): Response
    {
        $attendue = $requete->session()->pull(self::cleDeSession($jeton));
        $reference = (string) $requete->query('reference');
        $state = (string) $requete->query('state');

        if (! is_array($attendue) || ! hash_equals($attendue['reference'], $reference) || ! hash_equals($attendue['state'], $state)) {
            return $this->page($jeton, ApprouverMandatAction::demandeEnAttente($jeton), RetourVerificationIdentiteController::MESSAGE_SESSION_ETRANGERE);
        }

        try {
            $recevoir->handle($reference, $identite->resultat($reference, $state));
        } catch (IdentiteIndisponibleException $indisponible) {
            return $this->page($jeton, ApprouverMandatAction::demandeEnAttente($jeton), $indisponible->getMessage());
        }

        $demande = DemandeAccordDirigeant::withoutGlobalScope(SocieteScope::class)->where('jeton_hache', DemandeAccordDirigeant::empreinte($jeton))->first();

        return $this->page($jeton, null, $demande?->approuvee_le !== null ? 'Merci : le mandat est approuvé.' : 'Votre identité n\'a pas permis d\'approuver le mandat.');
    }

    public function refuser(string $jeton, ApprouverMandatAction $approuver): Response
    {
        try {
            $approuver->refuser($jeton);
        } catch (VerificationRefuseeException $refus) {
            return $this->page($jeton, null, $refus->getMessage());
        }

        return $this->page($jeton, null, 'Votre refus est enregistré : le demandeur en est prévenu.');
    }

    private static function cleDeSession(string $jeton): string
    {
        return 'accord_dirigeant.'.DemandeAccordDirigeant::empreinte($jeton);
    }

    private function page(string $jeton, ?DemandeAccordDirigeant $demande, ?string $message): Response
    {
        return response()
            ->view('accord-dirigeant.afficher', [
                'demande' => $demande,
                'message' => $message,
                'jeton' => $jeton,
            ], $demande === null && $message === null ? 410 : 200)
            ->header('Referrer-Policy', 'no-referrer');
    }
}
