<?php

namespace App\Domain\Tenancy\Isolation;

/**
 * Le rapport de la vérification de l'isolation de toute la base (voir
 * VerificationDeLIsolation) : chaque contrôle, dans l'ordre où il a tourné,
 * avec ses lignes vérifiées et ses problèmes.
 */
final readonly class RapportDIsolation
{
    /**
     * @param  list<ResultatDUnControle>  $controles
     */
    public function __construct(public array $controles) {}

    /**
     * @return list<ProblemeDIsolation>
     */
    public function problemes(): array
    {
        return array_merge([], ...array_map(static fn (ResultatDUnControle $controle): array => $controle->problemes(), $this->controles));
    }

    public function nombreDeProblemes(): int
    {
        return count($this->problemes());
    }

    public function estJuste(): bool
    {
        return $this->nombreDeProblemes() === 0;
    }

    public function lignesVerifiees(): int
    {
        return array_sum(array_map(static fn (ResultatDUnControle $controle): int => $controle->lignesVerifiees(), $this->controles));
    }

    /**
     * Le nombre de problèmes de chaque contrôle qui en a trouvé, par nom.
     *
     * @return array<string, int>
     */
    public function problemesParControle(): array
    {
        $nombres = [];

        foreach ($this->controles as $controle) {
            if ($controle->problemes() !== []) {
                $nombres[$controle->nom] = count($controle->problemes());
            }
        }

        return $nombres;
    }
}
