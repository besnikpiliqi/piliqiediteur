<?php

namespace App\Domain\Demonstration;

/**
 * Les hôtes que sert ce serveur (lot DEMO-COPIE, A.10) : hors du
 * développement et des tests, une requête pour un autre hôte répond 400
 * (TrustHosts, voir bootstrap/app.php), si bien qu'une adresse absolue
 * construite depuis la requête, comme celle d'un e-mail « Mot de passe
 * oublié », ne mène jamais ailleurs qu'au logiciel par un en-tête Host
 * forgé.
 *
 * La liste est fermée : les noms exacts de HOTES_DE_CONFIANCE, ou le seul
 * hôte d'APP_URL, jamais leurs sous-domaines. Le site de démonstration a
 * son propre domaine, jamais un sous-domaine du site réel : chaque site
 * ne répond qu'à ses hôtes.
 */
final class HotesDeConfiance
{
    /**
     * Les motifs exacts des hôtes servis.
     *
     * @return list<string>
     */
    public static function motifs(): array
    {
        $hotes = array_values(array_filter((array) config('piliqi.hotes_de_confiance', []), is_string(...)));

        if ($hotes === []) {
            $hote = parse_url((string) (is_string(config('app.url')) ? config('app.url') : ''), PHP_URL_HOST);
            $hotes = is_string($hote) && $hote !== '' ? [$hote] : [];
        }

        return array_map(static fn (string $hote): string => '^'.preg_quote(strtolower($hote)).'$', $hotes);
    }
}
