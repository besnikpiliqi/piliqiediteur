<?php

namespace App\Domain\Tenancy\Isolation;

/**
 * Le résultat d'un contrôle de l'isolation (voir VerificationDeLIsolation) :
 * son nom, la couche qui le porte (« SQL » ou « Laravel »), le nombre de
 * lignes qu'il a vérifiées et les problèmes qu'il a trouvés.
 */
final class ResultatDUnControle
{
    /** @var list<ProblemeDIsolation> */
    private array $problemes = [];

    private int $lignes = 0;

    public function __construct(
        public readonly string $nom,
        public readonly string $couche,
    ) {}

    public function compter(int $lignes): void
    {
        $this->lignes += $lignes;
    }

    /**
     * Note un problème de ce contrôle, dans sa couche.
     */
    public function signaler(string $table, string $identifiant, string $colonne, string $attendu, string $trouve, string $regle, ?string $couche = null): void
    {
        $this->problemes[] = new ProblemeDIsolation($couche ?? $this->couche, $this->nom, $table, $identifiant, $colonne, $attendu, $trouve, $regle);
    }

    public function lignesVerifiees(): int
    {
        return $this->lignes;
    }

    /**
     * @return list<ProblemeDIsolation>
     */
    public function problemes(): array
    {
        return $this->problemes;
    }
}
