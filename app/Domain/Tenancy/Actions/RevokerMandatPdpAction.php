<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\StatutMandatPdp;
use App\Domain\Tenancy\Models\MandatPdp;
use App\Integrations\Pdp\Contracts\PdpGatewayInterface;

class RevokerMandatPdpAction
{
    public function __construct(private readonly PdpGatewayInterface $gateway) {}

    public function handle(MandatPdp $mandat): MandatPdp
    {
        $this->gateway->revoquerMandat($mandat);

        $mandat->forceFill([
            'statut' => StatutMandatPdp::Revoque,
            'revoque_a' => now(),
        ])->save();

        return $mandat;
    }
}
