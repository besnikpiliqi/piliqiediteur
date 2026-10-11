<?php

namespace App\Integrations\EnvoiRecommande;

use App\Integrations\EnvoiRecommande\Contracts\ServiceEnvoiRecommande;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Le prestataire d'envoi factice du développement et des tests, sans
 * aucune requête (voir VerificationServiceProvider) : chaque pli est noté,
 * déposé, avec son corps (voir envois()), et reste déposé tant qu'un test
 * ne le remet pas ou ne le retourne pas (voir remettre(), retourner()).
 *
 * Les plis se gardent dans le cache de l'application, en tableaux simples
 * (le cache ne désérialise aucun objet), comme un prestataire les
 * garderait chez lui : le suivi et la saisie du code, qui
 * arrivent dans d'autres requêtes (d'autres processus en développement),
 * les retrouvent. En développement (environnement local seulement), chaque
 * pli s'écrit aussi au journal de l'application, code compris, pour qu'on
 * puisse le saisir : ce prestataire ne sert jamais en production (voir
 * VerificationServiceProvider::boot()). Le cache des tests ne vit que le
 * temps d'un test.
 */
class FauxServiceEnvoiRecommande implements ServiceEnvoiRecommande
{
    public const COURRIER = 'courrier';

    public const LRE = 'lre';

    public const LETTRE_SIMPLE = 'lettre_simple';

    /**
     * La clé du cache où se gardent les plis.
     */
    public const CLE_DU_CACHE = 'faux_service_envoi_recommande.envois';

    private bool $injoignable = false;

    public function envoyerCourrier(AdressePostale $destinataire, string $objet, array $corps): string
    {
        return $this->noter(self::COURRIER, $destinataire, $objet, $corps);
    }

    public function envoyerLre(string $nomDuDestinataire, string $email, string $objet, array $corps): string
    {
        return $this->noter(self::LRE, ['nom' => $nomDuDestinataire, 'email' => $email], $objet, $corps);
    }

    public function envoyerLettreSimple(AdressePostale $destinataire, string $objet, array $corps): string
    {
        return $this->noter(self::LETTRE_SIMPLE, $destinataire, $objet, $corps);
    }

    public function suivre(string $reference): SuiviDEnvoi
    {
        $this->repondre();

        return $this->envois()[$reference]['suivi'] ?? new SuiviDEnvoi(SuiviDEnvoi::DEPOSE);
    }

    /**
     * Les plis envoyés, par référence, dans l'ordre.
     *
     * @return array<string, array{genre: string, destinataire: AdressePostale|array{nom: string, email: string}, objet: string, corps: list<string>, suivi: SuiviDEnvoi}>
     */
    public function envois(): array
    {
        return array_map(fn (array $envoi): array => [
            'genre' => $envoi['genre'],
            'destinataire' => $envoi['adresse'] === null ? $envoi['destinataire'] : new AdressePostale(...$envoi['adresse']),
            'objet' => $envoi['objet'],
            'corps' => $envoi['corps'],
            'suivi' => SuiviDEnvoi::depuisLeRappel($envoi['suivi']),
        ], Cache::get(self::CLE_DU_CACHE, []));
    }

    /**
     * Le code de confirmation que porte le dernier pli recommandé, tel que
     * son destinataire le lit (XXXX-XXXX-XXXX), null s'il n'en porte pas.
     */
    public function codeDuDernierPli(): ?string
    {
        $dernier = collect($this->envois())->last(fn (array $envoi): bool => $envoi['genre'] !== self::LETTRE_SIMPLE);

        return $dernier === null ? null : (preg_match('/\b([0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4})\b/', implode("\n", $dernier['corps']), $code) === 1 ? $code[1] : null);
    }

    /**
     * Le pli est remis contre signature ; une lettre électronique, acceptée
     * par la personne que le prestataire a identifiée.
     *
     * @param  array<mixed>|null  $identite  la réponse brute du prestataire sur l'identité du destinataire
     */
    public function remettre(string $reference, ?array $identite = null): void
    {
        $this->suivreLePli($reference, ['statut' => SuiviDEnvoi::REMIS, 'identite' => $identite]);
    }

    public function retourner(string $reference): void
    {
        $this->suivreLePli($reference, ['statut' => SuiviDEnvoi::RETOURNE]);
    }

    public function rendreInjoignable(bool $injoignable = true): void
    {
        $this->injoignable = $injoignable;
    }

    /**
     * @param  AdressePostale|array{nom: string, email: string}  $destinataire
     * @param  list<string>  $corps
     *
     * @throws EnvoiRecommandeIndisponibleException
     */
    private function noter(string $genre, AdressePostale|array $destinataire, string $objet, array $corps): string
    {
        $this->repondre();
        $reference = 'faux-'.Str::lower(Str::random(16));
        Cache::forever(self::CLE_DU_CACHE, [...Cache::get(self::CLE_DU_CACHE, []), $reference => [
            'genre' => $genre,
            'adresse' => $destinataire instanceof AdressePostale ? [$destinataire->denomination, $destinataire->adresse, $destinataire->complementAdresse, $destinataire->codePostal, $destinataire->ville, $destinataire->source] : null,
            'destinataire' => $destinataire instanceof AdressePostale ? null : $destinataire,
            'objet' => $objet,
            'corps' => $corps,
            'suivi' => ['statut' => SuiviDEnvoi::DEPOSE],
        ]]);

        if (app()->isLocal()) {
            Log::info("Pli factice {$genre} {$reference} : {$objet}", ['destinataire' => $destinataire instanceof AdressePostale ? $destinataire->lignes() : $destinataire, 'corps' => $corps]);
        }

        return $reference;
    }

    /**
     * @param  array<mixed>  $suivi  le suivi tel qu'un rappel du prestataire le donnerait (voir SuiviDEnvoi::depuisLeRappel())
     */
    private function suivreLePli(string $reference, array $suivi): void
    {
        $envois = Cache::get(self::CLE_DU_CACHE, []);
        $envois[$reference]['suivi'] = $suivi;
        Cache::forever(self::CLE_DU_CACHE, $envois);
    }

    /**
     * @throws EnvoiRecommandeIndisponibleException
     */
    private function repondre(): void
    {
        if ($this->injoignable) {
            throw new EnvoiRecommandeIndisponibleException;
        }
    }
}
