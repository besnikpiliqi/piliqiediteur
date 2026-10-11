<?php

namespace App\Integrations\Identite;

use App\Integrations\Identite\Contracts\VerificationIdentite;
use Illuminate\Support\Str;

/**
 * La vérification d'identité factice du développement et des tests, sans
 * aucune requête (voir VerificationServiceProvider) : la session se passe
 * aussitôt, son adresse ramène directement à l'adresse de retour avec sa
 * référence et son state, et la personne vérifiée est Jeanne DUPONT, née
 * en janvier 1980, présidente de toute entreprise du registre factice
 * (voir FauxRne).
 *
 * Un test règle ses propres réponses (voir repondreAvec(),
 * rendreInjoignable()) sur l'instance du conteneur, et lit les sessions
 * ouvertes (voir sessions()).
 */
class FausseVerificationIdentite implements VerificationIdentite
{
    /**
     * La réponse par défaut du prestataire factice.
     */
    public const REPONSE_PAR_DEFAUT = [
        'statut' => 'verifiee',
        'nom' => 'DUPONT',
        'prenoms' => ['Jeanne'],
        'date_naissance' => '1980-01-15',
        'niveau_garantie' => 'substantiel',
    ];

    private bool $injoignable = false;

    /** @var array<mixed> */
    private array $reponse = self::REPONSE_PAR_DEFAUT;

    /** @var array<string, array{demande: DemandeDIdentite, reponse: array<mixed>}> */
    private array $sessions = [];

    public function demarrer(DemandeDIdentite $demande): SessionDIdentite
    {
        $this->repondre();
        $reference = 'faux-'.Str::lower(Str::random(20));
        $this->sessions[$reference] = ['demande' => $demande, 'reponse' => $this->reponse];
        $separateur = str_contains($demande->urlDeRetour, '?') ? '&' : '?';

        return new SessionDIdentite($reference, $demande->urlDeRetour.$separateur.http_build_query(['reference' => $reference, 'state' => $demande->state]));
    }

    public function resultat(string $reference, string $state): ResultatDIdentite
    {
        $this->repondre();
        $session = $this->sessions[$reference] ?? null;

        if ($session === null || ! hash_equals($session['demande']->state, $state)) {
            throw new IdentiteIndisponibleException('Cette vérification d\'identité est inconnue du prestataire.');
        }

        return ResultatDIdentite::depuisLaReponse($session['reponse']);
    }

    /**
     * La réponse brute que donneront les sessions ouvertes ensuite, telle
     * qu'un prestataire l'enverrait (voir ResultatDIdentite::depuisLaReponse()).
     *
     * @param  array<mixed>  $reponse
     */
    public function repondreAvec(array $reponse): void
    {
        $this->reponse = $reponse;
    }

    /**
     * @return array<string, array{demande: DemandeDIdentite, reponse: array<mixed>}>
     */
    public function sessions(): array
    {
        return $this->sessions;
    }

    public function rendreInjoignable(bool $injoignable = true): void
    {
        $this->injoignable = $injoignable;
    }

    /**
     * @throws IdentiteIndisponibleException
     */
    private function repondre(): void
    {
        if ($this->injoignable) {
            throw new IdentiteIndisponibleException;
        }
    }
}
