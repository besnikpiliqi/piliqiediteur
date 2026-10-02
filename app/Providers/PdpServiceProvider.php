<?php

namespace App\Providers;

use App\Integrations\Pdp\Contracts\PdpGatewayInterface;
use App\Integrations\Pdp\FakePdpGateway;
use Illuminate\Support\ServiceProvider;

/**
 * Seul point de câblage vers l'implémentation PDP active. Tant qu'aucun
 * vendeur réel n'est choisi, tout pointe vers FakePdpGateway. Le jour où
 * un vendeur est retenu, ce binding devient :
 *
 *   $this->app->bind(PdpGatewayInterface::class, match (config('services.pdp.driver')) {
 *       'fake' => FakePdpGateway::class,
 *       'nom_du_vendeur' => NomDuVendeurPdpGateway::class,
 *   });
 *
 * Aucun autre fichier du domaine Facturation/Tenancy n'a besoin de changer.
 */
class PdpServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PdpGatewayInterface::class, FakePdpGateway::class);
    }
}
