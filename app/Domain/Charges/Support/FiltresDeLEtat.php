<?php

namespace App\Domain\Charges\Support;

use App\Domain\Charges\Services\ChargesDeLaSociete;

/**
 * Les filtres de la page des charges que reprend son état téléchargeable
 * (voir EtatDesCharges) : les douze mois de la période et son libellé, la
 * vue (une clé de ChargesDeLaSociete::VUES), et le magasin du filtre (un
 * magasin que l'accès voit, ou « Toute la société » seulement avec
 * $sansMagasin, ou tous) avec son libellé, tels que la page les montre.
 */
final readonly class FiltresDeLEtat
{
    /**
     * @param  list<string>  $mois  « AAAA-MM »
     * @param  string  $periode  son libellé dans le menu de la page (« Année 2026 »)
     */
    public function __construct(
        public array $mois,
        public string $periode,
        public string $vue,
        public ?int $magasin,
        public bool $sansMagasin,
        public string $libelleDuMagasin,
    ) {}

    public function libelleDeLaVue(): string
    {
        return ChargesDeLaSociete::VUES[$this->vue] ?? ChargesDeLaSociete::VUES['toutes'];
    }
}
