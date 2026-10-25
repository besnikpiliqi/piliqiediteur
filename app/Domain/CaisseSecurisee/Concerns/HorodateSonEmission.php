<?php

namespace App\Domain\CaisseSecurisee\Concerns;

use App\Domain\CaisseSecurisee\Casts\HorodatageUtc;
use App\Domain\Calendrier\Calendrier;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Un document signé au registre fiscal à son émission (ticket, facture,
 * avoir) : sa date d'émission est l'instant du registre, enregistrée en
 * UTC et relue à l'heure de Paris (voir HorodatageUtc), pour que ce qu'il
 * signe se relise au même instant toute l'année, heure qui se répète au
 * passage à l'heure d'hiver comprise.
 *
 * La colonne étant en UTC, une période de jours du calendrier se cherche
 * par les instants de ses bornes (emisDuAu(), emisLe(), emisJusquA()).
 */
trait HorodateSonEmission
{
    public function initializeHorodateSonEmission(): void
    {
        $this->mergeCasts(['date_emission' => HorodatageUtc::class.':'.Calendrier::deParis()->fuseau()]);
    }

    /**
     * Les documents émis du jour $du au jour $au du calendrier, compris :
     * du minuit de $du au minuit qui suit $au, exclu.
     */
    public function scopeEmisDuAu(Builder $query, string $du, string $au): Builder
    {
        $calendrier = Calendrier::deParis();

        return $query
            ->where('date_emission', '>=', HorodatageUtc::enBase($calendrier->debutDuJour($du)))
            ->where('date_emission', '<', HorodatageUtc::enBase($calendrier->bornesDuJour($au)[1]));
    }

    /**
     * Les documents émis jusqu'à cet instant compris, quel que soit son
     * fuseau.
     */
    public function scopeEmisJusquA(Builder $query, CarbonInterface $instant): Builder
    {
        return $query->where('date_emission', '<=', HorodatageUtc::enBase($instant));
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
