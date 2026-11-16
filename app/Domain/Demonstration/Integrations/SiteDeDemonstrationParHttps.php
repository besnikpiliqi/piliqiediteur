<?php

namespace App\Domain\Demonstration\Integrations;

use App\Domain\Demonstration\Contracts\SiteDeDemonstration;
use App\Domain\Demonstration\Exceptions\SiteDeDemonstrationInjoignable;
use App\Domain\Demonstration\Messages\ReponseDuSiteDeDemonstration;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Le site de démonstration joint par HTTPS, à l'adresse DEMONSTRATION_URL
 * (voir SiteDeDemonstration) : chaque message part à POST
 * /interne/messages, en JSON, avec un délai court ; une réponse hors du
 * protocole ou une coupure laisse le message dans la boîte d'envoi.
 */
final class SiteDeDemonstrationParHttps implements SiteDeDemonstration
{
    public const DELAI_EN_SECONDES = 10;

    public function estLance(): bool
    {
        return $this->origine() !== null;
    }

    public function origine(): ?string
    {
        $url = config('demonstration.url');

        if (! is_string($url) || $url === '') {
            return null;
        }

        $parties = parse_url($url);

        if (! is_array($parties) || ! isset($parties['scheme'], $parties['host'])) {
            return null;
        }

        return $parties['scheme'].'://'.$parties['host'].(isset($parties['port']) ? ':'.$parties['port'] : '');
    }

    public function envoyer(array $enveloppe): ReponseDuSiteDeDemonstration
    {
        $origine = $this->origine() ?? throw new SiteDeDemonstrationInjoignable('Le site de démonstration n\'est pas réglé (DEMONSTRATION_URL).');

        try {
            $reponse = Http::timeout(self::DELAI_EN_SECONDES)->acceptJson()->asJson()->post($origine.'/interne/messages', $enveloppe);
        } catch (ConnectionException) {
            throw new SiteDeDemonstrationInjoignable('Le site de démonstration ne répond pas.');
        }

        if (! in_array($reponse->status(), [200, 202, 409, 410, 422], true)) {
            throw new SiteDeDemonstrationInjoignable('Le site de démonstration a répondu '.$reponse->status().'.');
        }

        return ReponseDuSiteDeDemonstration::depuis($reponse->status(), $reponse->json());
    }

    public function lireLEtat(): array
    {
        $origine = $this->origine() ?? throw new SiteDeDemonstrationInjoignable('Le site de démonstration n\'est pas réglé (DEMONSTRATION_URL).');

        try {
            $reponse = Http::timeout(self::DELAI_EN_SECONDES)->acceptJson()->get($origine.'/interne/etat');
        } catch (ConnectionException) {
            throw new SiteDeDemonstrationInjoignable('Le site de démonstration ne répond pas.');
        }

        $etat = $reponse->json('etat');
        $signature = $reponse->json('signature');

        if (! $reponse->ok() || ! is_string($etat) || ! is_string($signature)) {
            throw new SiteDeDemonstrationInjoignable('Le site de démonstration a répondu '.$reponse->status().' sans état lisible.');
        }

        return ['etat' => $etat, 'signature' => $signature];
    }
}
