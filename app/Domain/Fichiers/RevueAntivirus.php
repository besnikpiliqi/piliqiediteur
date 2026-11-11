<?php

namespace App\Domain\Fichiers;

use App\Integrations\Antivirus\AntivirusInjoignableException;
use App\Integrations\Antivirus\Contracts\Antivirus;
use App\Integrations\Antivirus\ResultatDeLAntivirus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * La nouvelle analyse d'un fichier conservé au moment de le servir
 * (décision de l'éditeur du 07/11/2026, lot FI) : une pièce téléchargée
 * seule, ou mise dans un ZIP, ou consultée par l'éditeur, est analysée de
 * nouveau quand sa dernière analyse date de plus de VALIDITE_EN_HEURES
 * heures, l'antivirus ayant pu apprendre depuis à reconnaître une menace.
 * Un antivirus injoignable fait refuser le téléchargement : une pièce
 * n'est jamais servie sans une analyse de moins de 24 heures.
 */
final class RevueAntivirus
{
    public const VALIDITE_EN_HEURES = 24;

    public function __construct(private readonly Antivirus $antivirus) {}

    /**
     * Une pièce analysée à cet instant doit-elle l'être de nouveau avant
     * d'être servie ?
     */
    public function aRevoir(CarbonInterface $analyseeLe): bool
    {
        return $analyseeLe->lt(CarbonImmutable::now()->subHours(self::VALIDITE_EN_HEURES));
    }

    /**
     * @throws AntivirusInjoignableException
     */
    public function analyser(string $contenu): ResultatDeLAntivirus
    {
        return $this->antivirus->analyser($contenu);
    }
}
