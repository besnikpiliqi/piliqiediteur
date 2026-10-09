<?php

namespace App\Providers;

use App\Domain\Achats\Events\AvoirFournisseurEmis;
use App\Domain\Achats\Events\FactureAchatValidee;
use App\Domain\Achats\Listeners\DecrementerStockApresEmissionAvoirFournisseur;
use App\Domain\Achats\Listeners\IncrementerStockApresValidationFactureAchat;
use App\Domain\CaisseSecurisee\Listeners\JournaliserFinDeSession;
use App\Domain\Facturation\Events\AvoirEmis;
use App\Domain\Facturation\Events\FactureEmise;
use App\Domain\Facturation\Events\SoumissionEReportingGeneree;
use App\Domain\Facturation\Listeners\AppliquerAvoirDisponibleApresEmissionFacture;
use App\Domain\Facturation\Listeners\DeclencherTransmissionPdpApresEmissionFacture;
use App\Domain\Facturation\Listeners\DeclencherTransmissionPdpApresSoumissionEReporting;
use App\Domain\Facturation\Listeners\DecrementerStockApresEmissionFacture;
use App\Domain\Facturation\Listeners\ImputerAvoirSurSesFacturesApresEmission;
use App\Domain\Facturation\Listeners\RestaurerStockApresEmissionAvoir;
use App\Domain\Stock\Events\StockPasseSousLeSeuilDAlerte;
use App\Domain\Stock\Listeners\NotifierLeStockSousLeSeuilDAlerte;
use Illuminate\Auth\Events\CurrentDeviceLogout;
use Illuminate\Auth\Events\Logout;
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
        Event::listen(FactureEmise::class, AppliquerAvoirDisponibleApresEmissionFacture::class);
        Event::listen(FactureEmise::class, DeclencherTransmissionPdpApresEmissionFacture::class);
        Event::listen(AvoirEmis::class, RestaurerStockApresEmissionAvoir::class);
        Event::listen(AvoirEmis::class, ImputerAvoirSurSesFacturesApresEmission::class);
        Event::listen(SoumissionEReportingGeneree::class, DeclencherTransmissionPdpApresSoumissionEReporting::class);
        Event::listen(FactureAchatValidee::class, IncrementerStockApresValidationFactureAchat::class);
        Event::listen(AvoirFournisseurEmis::class, DecrementerStockApresEmissionAvoirFournisseur::class);
        Event::listen(StockPasseSousLeSeuilDAlerte::class, NotifierLeStockSousLeSeuilDAlerte::class);
        Event::listen(Logout::class, JournaliserFinDeSession::class);
        Event::listen(CurrentDeviceLogout::class, JournaliserFinDeSession::class);
    }
}
