<?php

namespace App\Domain\CaisseSecurisee\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Models\Magasin;
use Carbon\CarbonImmutable;

/**
 * L'instant d'une transaction fiscale, que donne RegistreFiscal::verrouiller()
 * une fois le registre du magasin verrouillé : en UTC, à la seconde, jamais
 * antérieur au dernier verrou du registre du magasin, avec le calendrier de
 * ce magasin (voir Magasin::calendrier()), qui en donne le jour et l'année.
 * Toutes les écritures fiscales de la transaction, transactions imbriquées
 * comprises, portent cet instant.
 */
final class InstantFiscal
{
    private ?Calendrier $calendrier = null;

    public function __construct(
        public readonly CarbonImmutable $utc,
        private readonly int $magasinId,
    ) {}

    /**
     * Le calendrier du magasin, relu à la première demande d'un jour ou
     * d'une année de la transaction, une seule fois : sous le verrou du
     * registre, que tient la transaction, un territoire fixé par une
     * transaction qui tenait ce verrou avant elle s'y lit déjà (chaque
     * fixation du territoire le tient : elle s'inscrit au journal des
     * événements du magasin, voir JournaliseSesParametresDeConformite).
     * Une transaction qui ne demande aucun jour (un événement du journal)
     * ne le relit pas, et aucune lecture ne s'intercale entre le verrou du
     * registre et celles de l'opération qui l'a pris.
     */
    public function calendrier(): Calendrier
    {
        return $this->calendrier ??= Magasin::query()->select(['id', 'territoire'])->findOrFail($this->magasinId)->calendrier();
    }

    /**
     * L'horodatage tel qu'il s'écrit dans le contenu signé :
     * AAAAMMJJHHMMSS suivi de Z (UTC).
     */
    public function pourSignature(): string
    {
        return Calendrier::horodatageSigne($this->utc);
    }

    /**
     * Le jour de cet instant dans le calendrier du magasin (AAAA-MM-JJ) : la
     * journée de caisse d'une vente, le jour de réception d'un règlement, le
     * point de départ d'une échéance.
     */
    public function jour(): string
    {
        return $this->calendrier()->jourDe($this->utc);
    }

    /**
     * L'année de cet instant dans le calendrier du magasin (AAAA) : celle
     * des numéros d'un document.
     */
    public function annee(): string
    {
        return $this->calendrier()->anneeDe($this->utc);
    }
}
