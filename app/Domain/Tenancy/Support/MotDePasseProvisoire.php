<?php

namespace App\Domain\Tenancy\Support;

use Carbon\CarbonImmutable;
use LogicException;
use SensitiveParameter;

/**
 * Un mot de passe provisoire tiré au hasard (voir MotsDePasseProvisoires),
 * en clair : il ne vit que le temps de le montrer, une seule fois, à qui
 * crée le compte ou redonne l'accès, pour qu'il le remette en main propre.
 * La base n'en garde que l'empreinte ; l'objet ne se sérialise pas, ne se
 * déverse dans aucun journal et ne se lit que par valeur().
 */
final class MotDePasseProvisoire
{
    public function __construct(
        #[SensitiveParameter] private readonly string $valeur,
        public readonly CarbonImmutable $expireLe,
    ) {}

    public function valeur(): string
    {
        return $this->valeur;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['valeur' => '***'];
    }

    /**
     * @return array<never, never>
     *
     * @throws LogicException toujours : un mot de passe provisoire ne s'enregistre jamais en clair
     */
    public function __serialize(): array
    {
        throw new LogicException('Un mot de passe provisoire ne se sérialise pas.');
    }
}
