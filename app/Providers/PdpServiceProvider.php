<?php

namespace App\Providers;

use App\Integrations\Pdp\Contracts\PdpGatewayInterface;
use App\Integrations\Pdp\FakePdpGateway;
use Illuminate\Support\ServiceProvider;

/**
 * Seul point de câblage vers l'implémentation PDP active : la passerelle
 * factice FakePdpGateway, jusqu'au raccordement de SUPER PDP (étape 11 de
 * la feuille de route). Aucun autre fichier du domaine Facturation/Tenancy
 * n'aura besoin de changer.
 */
class PdpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PdpGatewayInterface::class, FakePdpGateway::class);
    }
}
