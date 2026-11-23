<?php

namespace App\Domain\Demonstration\Integrations;

use App\Domain\Demonstration\Contracts\SiteDeDemonstration;
use App\Domain\Demonstration\Exceptions\SiteDeDemonstrationInjoignable;
use App\Domain\Demonstration\Messages\ReponseDuSiteDeDemonstration;

/**
 * Un faux site de démonstration, pour le semis du poste (lot DEMO-COPIE,
 * F.7) : il répond à chaque message l'état que le semis lui fait dire, ne
 * crée aucune copie et ne joint rien. Son origine fictive n'existe pas :
 * le site réel ne peut jamais y envoyer un vrai visiteur.
 */
final class SiteDeDemonstrationFactice implements SiteDeDemonstration
{
    public const ORIGINE = 'https://demonstration.piliqi.invalid';

    /**
     * La prochaine réponse : son code et son corps, ou null pour une
     * coupure.
     *
     * @var array{0: int, 1: array<string, mixed>}|null
     */
    private ?array $reponse = [200, ['etat' => 'prete', 'generation' => 1]];

    /**
     * @param  array<string, mixed>  $corps
     */
    public function repondre(int $code, array $corps): void
    {
        $this->reponse = [$code, $corps];
    }

    public function couper(): void
    {
        $this->reponse = null;
    }

    public function estLance(): bool
    {
        return true;
    }

    public function origine(): string
    {
        return self::ORIGINE;
    }

    public function envoyer(array $enveloppe): ReponseDuSiteDeDemonstration
    {
        if ($this->reponse === null) {
            throw new SiteDeDemonstrationInjoignable('La démonstration ne répond pas (délai dépassé).');
        }

        $corps = $this->reponse[1];

        if (isset($corps['etat']) && ! isset($corps['generation'])) {
            $champs = json_decode($enveloppe['message'], true);
            $corps['generation'] = is_array($champs) && is_int($champs['generation'] ?? null) ? $champs['generation'] : null;
        }

        return ReponseDuSiteDeDemonstration::depuis($this->reponse[0], $corps);
    }

    /**
     * Le faux site ne publie aucun état : le semis ne le lit jamais.
     */
    public function lireLEtat(): array
    {
        throw new SiteDeDemonstrationInjoignable('Le faux site de démonstration ne publie pas d\'état.');
    }
}
