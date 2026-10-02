<?php

namespace App\Domain\Facturation\Events;

use App\Domain\Facturation\Models\Facture;
use Illuminate\Foundation\Events\Dispatchable;

class FactureAnnulee
{
    use Dispatchable;

    public function __construct(public readonly Facture $facture) {}
}
