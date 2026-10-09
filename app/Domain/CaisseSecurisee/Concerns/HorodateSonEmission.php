<?php

namespace App\Domain\CaisseSecurisee\Concerns;

use App\Domain\CaisseSecurisee\Casts\HorodatageUtc;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Un document signé au registre fiscal à son émission (ticket, facture,
 * avoir) : sa date d'émission est l'instant du registre, enregistrée en
 * UTC et relue à l'heure de Paris (voir HorodatageUtc), pour que ce qu'il
 * signe se relise au même instant toute l'année, heure qui se répète au
 * passage à l'heure d'hiver comprise.
 *
 * La colonne étant en UTC, une période de Paris se cherche par ses bornes
 * converties en UTC (emisEntre(), emisLe()), jamais par whereDate(), qui
 * comparerait la date UTC.
 */
trait HorodateSonEmission
{
    public function initializeHorodateSonEmission(): void
    {
        $this->mergeCasts(['date_emission' => HorodatageUtc::class.':'.config('app.timezone')]);
    }

    /**
     * Les documents émis de $debut à $fin, bornes comprises, quel que soit
     * leur fuseau.
     */
    public function scopeEmisEntre(Builder $query, CarbonInterface $debut, CarbonInterface $fin): Builder
    {
        return $query->whereBetween('date_emission', [HorodatageUtc::enBase($debut), HorodatageUtc::enBase($fin)]);
    }

    /**
     * Les documents émis tel jour à Paris, de minuit à minuit : 23, 24 ou
     * 25 heures selon le changement d'heure. Une date porte son jour dans
     * son propre fuseau, comme pour whereDate().
     */
    public function scopeEmisLe(Builder $query, CarbonInterface|string $jour): Builder
    {
        $minuit = CarbonImmutable::parse($jour instanceof CarbonInterface ? $jour->toDateString() : $jour, config('app.timezone'))->startOfDay();

        return $query->emisEntre($minuit, $minuit->endOfDay());
    }
}
