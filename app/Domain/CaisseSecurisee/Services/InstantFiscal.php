<?php

namespace App\Domain\CaisseSecurisee\Services;

use App\Domain\Calendrier\Calendrier;
use Carbon\CarbonImmutable;

/**
 * L'instant d'une transaction fiscale, que donne RegistreFiscal::verrouiller()
 * une fois le registre du magasin verrouillé : en UTC, à la seconde, jamais
 * antérieur au dernier verrou du registre du magasin. Toutes les écritures
 * fiscales de la transaction, transactions imbriquées comprises, portent
 * cet instant.
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
        return Calendrier::horodatageSigne($this->utc);
    }

    /**
     * Le jour du calendrier de cet instant (AAAA-MM-JJ) : la journée de
     * caisse d'une vente, le jour de réception d'un règlement, le point de
     * départ d'une échéance.
     */
    public function jour(): string
    {
        return Calendrier::deParis()->jourDe($this->utc);
    }

    /**
     * L'année du calendrier de cet instant (AAAA) : celle des numéros d'un
     * document.
     */
    public function annee(): string
    {
        return Calendrier::deParis()->anneeDe($this->utc);
    }
}
