<?php

namespace App\Domain\CaisseSecurisee\Services;

use App\Domain\CaisseSecurisee\Enums\CodeEvenement;

/**
 * Une anomalie que relève la vérification de l'intégrité du registre
 * fiscal (voir VerificateurIntegrite), avec le code de l'événement
 * technique qui la journalise : une rupture de séquence ou de numérotation
 * (JET 95), ou tout autre défaut d'intégrité (JET 90).
 *
 * - `controle` : le contrôle qui l'a relevée, en un mot stable (sequence,
 *   chainage, signature, en_tete, document, numerotation...) ;
 * - `message` : ce qui ne va pas, rédigé pour le magasin et pour
 *   l'administration ;
 * - `reference` : ce qui la situe (chaîne et séquence, document, série),
 *   en entiers et chaînes seulement, comme tout ce qui se signe.
 */
final readonly class AnomalieFiscale
{
    /**
     * @param  array<string, int|string|null>  $reference
     */
    private function __construct(
        public CodeEvenement $code,
        public string $controle,
        public string $message,
        public array $reference,
    ) {}

    /**
     * Un enregistrement manquant dans une chaîne, ou un numéro manquant ou
     * hors série dans la numérotation des documents.
     *
     * @param  array<string, int|string|null>  $reference
     */
    public static function rupture(string $controle, string $message, array $reference = []): self
    {
        return new self(CodeEvenement::RuptureDeSequence, $controle, $message, $reference);
    }

    /**
     * Tout autre défaut : signature, chaînage, en-tête, document modifié
     * depuis sa signature, imputation non signée...
     *
     * @param  array<string, int|string|null>  $reference
     */
    public static function defaut(string $controle, string $message, array $reference = []): self
    {
        return new self(CodeEvenement::DefautDIntegrite, $controle, $message, $reference);
    }

    /**
     * Les détails de l'événement technique qui la journalise.
     *
     * @return array<string, int|string|null>
     */
    public function details(): array
    {
        return ['controle' => $this->controle, 'message' => $this->message, ...$this->reference];
    }
}
