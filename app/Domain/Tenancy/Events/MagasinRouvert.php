<?php

namespace App\Domain\Tenancy\Events;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un magasin réel d'une société validée, rouvert par sa société, commence
 * une nouvelle période d'activité (voir RouvrirMagasinAction et
 * MiseEnActiviteDesMagasins) : son abonnement reprend avec elle (étape
 * 12). Seulement une fois la transaction validée.
 */
class MagasinRouvert implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Magasin $magasin,
        public readonly PeriodeActivite $periode,
    ) {}
}
