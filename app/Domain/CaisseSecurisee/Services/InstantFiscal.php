<?php

namespace App\Domain\CaisseSecurisee\Services;

use Carbon\CarbonImmutable;

/**
 * L'instant d'une transaction fiscale, que donne RegistreFiscal::verrouiller()
 * une fois le registre du magasin verrouillé : en UTC, à la seconde, jamais
 * antérieur au dernier enregistrement du magasin. Toutes les écritures
 * fiscales de la transaction portent cet instant.
 */
final readonly class InstantFiscal
{
    public function __construct(public CarbonImmutable $utc) {}

    /**
     * L'horodatage tel qu'il s'écrit dans le contenu signé :
     * AAAAMMJJHHMMSS suivi de Z (UTC).
     */
    public function pourSignature(): string
    {
        return $this->utc->format('YmdHis').'Z';
    }
}
