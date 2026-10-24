<?php

namespace App\Domain\CaisseSecurisee\Services;

use App\Domain\CaisseSecurisee\Enums\ChaineFiscale;

/**
 * Le résultat d'une vérification de l'intégrité du registre fiscal d'un
 * magasin (voir VerificateurIntegrite) : le nombre de ses anomalies et les
 * premières d'entre elles, dans l'ordre où elle les a relevées, et, pour
 * chaque chaîne, le nombre d'enregistrements vérifiés et son point de
 * reprise, c'est-à-dire la séquence et la signature du dernier
 * enregistrement vérifié dont la signature se vérifie, d'où repartira la
 * vérification incrémentale suivante.
 */
final class RapportIntegrite
{
    /**
     * Le nombre d'anomalies que le rapport garde, au plus : une clé retirée
     * du dépôt rendrait chaque enregistrement suspect, et ni la mémoire de
     * la vérification ni le journal des événements, où chacune s'inscrit
     * (voir VerifierIntegriteAction), ne doivent grandir avec le registre.
     * Le rapport compte toujours toutes les anomalies.
     */
    public const ANOMALIES_RETENUES = 100;

    /**
     * @var list<AnomalieFiscale>
     */
    private array $anomalies = [];

    private int $nombreDAnomalies = 0;

    /**
     * @var array<string, int>
     */
    private array $enregistrementsVerifies = [];

    /**
     * @var array<string, array{sequence: int, signature: string}>
     */
    private array $pointsDeReprise = [];

    /**
     * @param  bool  $complet  chaque chaîne relue depuis son premier enregistrement, aucune ne repartant d'un point de reprise de la vérification précédente
     */
    public function __construct(public readonly bool $complet) {}

    public function signaler(AnomalieFiscale $anomalie): void
    {
        if (++$this->nombreDAnomalies <= self::ANOMALIES_RETENUES) {
            $this->anomalies[] = $anomalie;
        }
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
        return $this->nombreDAnomalies === 0;
    }

    /**
     * Les premières anomalies relevées, ANOMALIES_RETENUES au plus.
     *
     * @return list<AnomalieFiscale>
     */
    public function anomalies(): array
    {
        return $this->anomalies;
    }

    /**
     * Le nombre de toutes les anomalies relevées, même au-delà de celles
     * que le rapport garde.
     */
    public function nombreDAnomalies(): int
    {
        return $this->nombreDAnomalies;
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
