<?php

namespace App\Domain\Tenancy\Events;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un magasin réel fermé par sa société termine sa période d'activité, que
 * sa fin date et dont son motif dit la fermeture (temporaire, cessation,
 * cession ; voir FermerMagasinAction) : son abonnement s'arrête avec elle
 * (étape 12). Un magasin fermé avant la validation de sa société n'avait
 * aucune période, et n'en émet pas. Seulement une fois la transaction
 * validée.
 */
class MagasinFerme implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Magasin $magasin,
        public readonly PeriodeActivite $periode,
    ) {}
}
