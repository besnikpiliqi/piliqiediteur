<?php

namespace App\Domain\Tenancy\Events;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un magasin réel termine sa période d'activité, que sa fin date et dont
 * son motif dit la raison : sa fermeture par sa société (temporaire,
 * cessation, cession ; voir FermerMagasinAction), ou la révocation de sa
 * société par l'éditeur (voir RevoquerSocieteAction) : son abonnement
 * s'arrête avec elle (étape 12). Un magasin fermé avant la validation de
 * sa société n'avait aucune période, et n'en émet pas. Seulement une fois
 * la transaction validée.
 */
class MagasinFerme implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Magasin $magasin,
        public readonly PeriodeActivite $periode,
    ) {}
}
