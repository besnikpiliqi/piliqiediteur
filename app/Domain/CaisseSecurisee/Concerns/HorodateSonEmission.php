<?php

namespace App\Domain\CaisseSecurisee\Concerns;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Calendrier\Casts\Instant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Un document signé au registre fiscal à son émission (ticket, facture,
 * avoir) : sa date d'émission est l'instant du registre, en UTC (voir
 * Instant), qui se relit au même instant toute l'année, heure qui se
 * répète au passage à l'heure d'hiver comprise.
 *
 * Une période de jours du calendrier se cherche par les instants de ses
 * bornes (emisDuAu(), emisLe(), emisJusquA()).
 */
trait HorodateSonEmission
{
    public function initializeHorodateSonEmission(): void
    {
        $this->mergeCasts(['date_emission' => Instant::class]);
    }

    /**
     * Les documents émis du jour $du au jour $au du calendrier, compris :
     * du minuit de $du au minuit qui suit $au, exclu.
     */
    public function scopeEmisDuAu(Builder $query, string $du, string $au): Builder
    {
        $calendrier = Calendrier::deParis();

        return $query
            ->where('date_emission', '>=', $calendrier->debutDuJour($du))
            ->where('date_emission', '<', $calendrier->bornesDuJour($au)[1]);
    }

    /**
     * Les documents émis jusqu'à cet instant compris, quel que soit son
     * fuseau.
     */
    public function scopeEmisJusquA(Builder $query, CarbonInterface $instant): Builder
    {
        return $query->where('date_emission', '<=', $instant);
    }

    /**
     * Les documents émis tel jour du calendrier, de minuit à minuit : 23,
     * 24 ou 25 heures selon le changement d'heure.
     */
    public function scopeEmisLe(Builder $query, string $jour): Builder
    {
        return $query->emisDuAu($jour, $jour);
    }
}
