<?php

namespace App\Domain\Facturation\Events;

use App\Domain\Facturation\Models\ClotureCaisse;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Marque qu'une journée vient d'être clôturée. Ne déclenche PLUS de
 * transmission PDP directement : l'e-reporting n'est pas une obligation
 * journalière, cette clôture sera transmise plus tard, agrégée avec
 * d'autres, par la commande planifiée app:soumettre-ereporting-periodique
 * (voir SoumissionEReporting et GenererSoumissionEReportingAction).
 * Aucun listener n'y est actuellement rattaché ; conservé comme point
 * d'extension (notifications, etc.).
 */
class ClotureCaisseEffectuee
{
    use Dispatchable;

    public function __construct(public readonly ClotureCaisse $cloture) {}
}
