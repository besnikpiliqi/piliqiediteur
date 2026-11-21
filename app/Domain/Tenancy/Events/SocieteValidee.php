<?php

namespace App\Domain\Tenancy\Events;

use App\Domain\Tenancy\Models\Societe;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * La société vient d'être validée (voir ValiderLaSocieteAction) : ses
 * opérations commerciales réelles s'ouvrent, et ses abonnements peuvent
 * commencer (étape 12) ; chacun de ses magasins mis en activité à cette
 * occasion émet aussi MagasinOuvert. Seulement une fois la transaction
 * validée.
 */
class SocieteValidee implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Societe $societe) {}
}
