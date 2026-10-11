<?php

namespace App\Integrations\Identite;

/**
 * Une session de vérification d'identité ouverte chez le prestataire, à
 * usage unique : sa référence et l'adresse où la personne la passe.
 */
final readonly class SessionDIdentite
{
    public function __construct(
        public string $reference,
        public string $url,
    ) {}
}
