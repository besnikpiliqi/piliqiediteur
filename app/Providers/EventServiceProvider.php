<?php

namespace App\Providers;

use App\Domain\Facturation\Events\AvoirEmis;
use App\Domain\Facturation\Events\FactureAnnulee;
use App\Domain\Facturation\Events\FactureEmise;
use App\Domain\Facturation\Listeners\DeclencherTransmissionPdpApresEmissionFacture;
use App\Domain\Facturation\Listeners\DecrementerStockApresEmissionFacture;
use App\Domain\Facturation\Listeners\RestaurerStockApresAnnulationFacture;
use App\Domain\Facturation\Listeners\RestaurerStockApresEmissionAvoir;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Nos events/listeners vivent sous App\Domain\<Domaine>\{Events,Listeners}
 * plutôt que dans app/Listeners : l'auto-discovery de Laravel ne les
 * trouve pas dans ce répertoire par défaut. Enregistrement explicite,
 * cohérent avec le choix déjà fait pour les Policies et les Factories
 * (voir AppServiceProvider).
 */
class EventServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(FactureEmise::class, DecrementerStockApresEmissionFacture::class);
        Event::listen(FactureEmise::class, DeclencherTransmissionPdpApresEmissionFacture::class);
        Event::listen(FactureAnnulee::class, RestaurerStockApresAnnulationFacture::class);
        Event::listen(AvoirEmis::class, RestaurerStockApresEmissionAvoir::class);
    }
}
