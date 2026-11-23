<?php

namespace App\Integrations\Antivirus;

/**
 * Le verdict de l'antivirus sur un contenu : sain, ou la menace qu'il y a
 * trouvée, sous le nom que lui donne l'antivirus.
 */
final readonly class ResultatDeLAntivirus
{
    private function __construct(public ?string $menace) {}

    public static function sain(): self
    {
        return new self(null);
    }

    public static function menace(string $nom): self
    {
        return new self($nom);
    }

    public function estSain(): bool
    {
        return $this->menace === null;
    }
}
