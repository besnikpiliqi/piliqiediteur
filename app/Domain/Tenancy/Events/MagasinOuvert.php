<?php

namespace App\Domain\Tenancy\Events;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PeriodeActivite;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Un magasin réel d'une société validée commence sa première période
 * d'activité (voir MiseEnActiviteDesMagasins) : à la validation de sa
 * société, à sa création dans une société déjà validée, ou à la
 * réouverture d'un magasin fermé avant la validation. Son abonnement
 * commence avec cette période (étape 12) ; le magasin de démonstration
 * n'en émet jamais. Seulement une fois la transaction validée.
 */
class MagasinOuvert implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Magasin $magasin,
        public readonly PeriodeActivite $periode,
    ) {}
}
