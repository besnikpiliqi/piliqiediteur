<?php

namespace App\Domain\CaisseSecurisee\Services;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;

/**
 * Le résultat d'une vérification de l'intégrité du registre fiscal d'un
 * magasin (voir VerificateurIntegrite) : ses anomalies, dans l'ordre où
 * elle les a relevées, et, pour chaque chaîne, le nombre d'enregistrements
 * vérifiés et son point de reprise, c'est-à-dire la séquence et la
 * signature du dernier enregistrement vérifié, d'où repartira la
 * vérification incrémentale suivante.
 */
final class RapportIntegrite
{
    /**
     * @var list<AnomalieFiscale>
     */
    private array $anomalies = [];

    /**
     * @var array<string, int>
     */
    private array $enregistrementsVerifies = [];

    /**
     * @var array<string, array{sequence: int, signature: string}>
     */
    private array $pointsDeReprise = [];

    /**
     * @param  bool  $complet  tout le registre depuis son premier enregistrement, ou seulement ce qui s'y est inscrit depuis les points de reprise de la vérification précédente
     */
    public function __construct(public readonly bool $complet) {}

    public function signaler(AnomalieFiscale $anomalie): void
    {
        $this->anomalies[] = $anomalie;
    }

    /**
     * Ce que la vérification a parcouru de la chaîne : le nombre de ses
     * enregistrements vérifiés, et son point de reprise, s'il y en a un.
     *
     * @param  array{sequence: int, signature: string}|null  $pointDeReprise
     */
    public function noterLaChaine(ChaineFiscale $chaine, int $enregistrementsVerifies, ?array $pointDeReprise): void
    {
        $this->enregistrementsVerifies[$chaine->value] = $enregistrementsVerifies;

        if ($pointDeReprise !== null) {
            $this->pointsDeReprise[$chaine->value] = $pointDeReprise;
        }
    }

    public function estConforme(): bool
    {
        return $this->anomalies === [];
    }

    /**
     * @return list<AnomalieFiscale>
     */
    public function anomalies(): array
    {
        return $this->anomalies;
    }

    /**
     * @return array<string, int> par valeur de ChaineFiscale
     */
    public function enregistrementsVerifies(): array
    {
        return $this->enregistrementsVerifies;
    }

    public function nombreDEnregistrementsVerifies(): int
    {
        return array_sum($this->enregistrementsVerifies);
    }

    /**
     * @return array<string, array{sequence: int, signature: string}> par valeur de ChaineFiscale
     */
    public function pointsDeReprise(): array
    {
        return $this->pointsDeReprise;
    }
}
