<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Models\Devis;
use Illuminate\Support\Facades\DB;
use LogicException;

class RefuserDevisAction
{
    public function handle(Devis $devis): Devis
    {
        return DB::transaction(function () use ($devis) {
            $devis = Devis::query()->whereKey($devis->getKey())->lockForUpdate()->firstOrFail();

            if (! $devis->statut->peutTransitionnerVers(StatutDevis::Refuse)) {
                throw new LogicException("Impossible de refuser un devis au statut [{$devis->statut->value}].");
            }

            $devis->forceFill(['statut' => StatutDevis::Refuse])->save();

            return $devis;
        });
    }
}
