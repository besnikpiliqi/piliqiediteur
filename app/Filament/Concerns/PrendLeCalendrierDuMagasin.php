<?php

namespace App\Filament\Concerns;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Services\TenantContext;

/**
 * Le calendrier du magasin du panneau (voir Magasin::calendrier()) : ses
 * pages comptent et affichent leurs jours et leurs heures à l'heure de son
 * territoire, sans mention. Une liste le prend une fois, jamais en
 * relisant le magasin de chaque ligne.
 */
trait PrendLeCalendrierDuMagasin
{
    protected static function calendrierDuMagasin(): Calendrier
    {
        return app(TenantContext::class)->magasin()->calendrier();
    }
}
