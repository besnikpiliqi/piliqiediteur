<?php

namespace App\Domain\Facturation\Listeners;

use App\Domain\Facturation\Actions\ImputerAvoirSurSesFacturesAction;
use App\Domain\Facturation\Events\AvoirEmis;

/**
 * Écouteur SYNCHRONE, comme RestaurerStockApresEmissionAvoir : la facture
 * corrigée reflète l'avoir dans la même transaction que son émission.
 */
class ImputerAvoirSurSesFacturesApresEmission
{
    public function __construct(private readonly ImputerAvoirSurSesFacturesAction $action) {}

    public function handle(AvoirEmis $event): void
    {
        $this->action->handle($event->avoir);
    }
}
