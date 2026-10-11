<?php

namespace App\Integrations\Identite;

use App\Integrations\Identite\Contracts\VerificationIdentite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * La vérification d'identité factice du développement et des tests, sans
 * aucune requête (voir VerificationServiceProvider) : la session se passe
 * aussitôt, son adresse ramène directement à l'adresse de retour avec sa
 * référence et son state, et la personne vérifiée est Jeanne DUPONT, née
 * en janvier 1980, présidente de toute entreprise du registre factice
 * (voir FauxRne).
 *
 * Les sessions ouvertes se gardent dans le cache de l'application, en
 * tableaux simples (le cache ne désérialise aucun objet), comme un
 * prestataire les garderait chez lui : le retour, qui arrive dans une
 * autre requête (un autre processus en développement), les retrouve. Le
 * cache des tests ne vit que le temps d'un test.
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

    /**
     * La clé du cache où se gardent les sessions ouvertes.
     */
    public const CLE_DU_CACHE = 'fausse_verification_identite.sessions';

    private bool $injoignable = false;

    /** @var array<mixed> */
    private array $reponse = self::REPONSE_PAR_DEFAUT;

    public function demarrer(DemandeDIdentite $demande): SessionDIdentite
    {
        $this->repondre();
        $reference = 'faux-'.Str::lower(Str::random(20));
        Cache::forever(self::CLE_DU_CACHE, [
            ...Cache::get(self::CLE_DU_CACHE, []),
            $reference => ['texte_de_consentement' => $demande->texteDeConsentement, 'url_de_retour' => $demande->urlDeRetour, 'state' => $demande->state, 'reponse' => $this->reponse],
        ]);
        $separateur = str_contains($demande->urlDeRetour, '?') ? '&' : '?';

        return new SessionDIdentite($reference, $demande->urlDeRetour.$separateur.http_build_query(['reference' => $reference, 'state' => $demande->state]));
    }

    public function resultat(string $reference, string $state): ResultatDIdentite
    {
        $this->repondre();
        $session = $this->sessions()[$reference] ?? null;

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
        return array_map(fn (array $session): array => [
            'demande' => new DemandeDIdentite($session['texte_de_consentement'], $session['url_de_retour'], $session['state']),
            'reponse' => $session['reponse'],
        ], Cache::get(self::CLE_DU_CACHE, []));
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
