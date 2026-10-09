<?php

namespace App\Domain\CaisseSecurisee\Services;

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
        return $this->utc->format('YmdHis').'Z';
    }

    /**
     * Le même instant à l'heure de l'application (Europe/Paris) : l'année
     * de numérotation d'un document, le jour de réception d'un règlement.
     * La date d'émission d'un document s'enregistre, elle, en UTC (voir
     * HorodateSonEmission).
     */
    public function enHeureLocale(): CarbonImmutable
    {
        return $this->utc->setTimezone(config('app.timezone'));
    }
}
