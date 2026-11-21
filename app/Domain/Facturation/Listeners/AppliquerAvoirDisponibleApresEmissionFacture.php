<?php

namespace App\Domain\Facturation\Listeners;

use App\Domain\Facturation\Actions\AppliquerAvoirsDisponiblesAction;
use App\Domain\Facturation\Events\FactureEmise;

/**
 * Écouteur SYNCHRONE (pas de ShouldQueue), comme
 * DecrementerStockApresEmissionFacture : une facture émise doit refléter
 * immédiatement -- dans la même transaction que son émission -- le crédit
 * disponible de son client, pas quelques secondes plus tard dans une queue.
 */
class AppliquerAvoirDisponibleApresEmissionFacture
{
    public function __construct(private readonly AppliquerAvoirsDisponiblesAction $action) {}

    public function handle(FactureEmise $event): void
    {
        $this->action->handle($event->facture);
    }
}
