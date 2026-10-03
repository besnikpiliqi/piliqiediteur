<?php

namespace App\Domain\Facturation\Events;

use App\Domain\Facturation\Models\ClotureCaisse;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Délibérément PAS de ShouldDispatchAfterCommit : comme FactureEmise, ce
 * qui doit en découler dans la même transaction (ici : rien d'atomique
 * requis côté stock, contrairement à une facture) reste cohérent avec le
 * pattern du reste du domaine. Le listener PDP, lui, diffère SON dispatch
 * via ->afterCommit() (voir DeclencherTransmissionPdpApresClotureCaisse).
 */
class ClotureCaisseEffectuee
{
    use Dispatchable;

    public function __construct(public readonly ClotureCaisse $cloture) {}
}
