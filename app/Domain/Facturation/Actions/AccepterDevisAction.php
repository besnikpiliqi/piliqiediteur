<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Models\Devis;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Constate l'acceptation, verbale ou écrite, d'un devis par son client :
 * seule cette acceptation explicite, et non un simple envoi, permet de le
 * convertir en facture (voir CreerFactureDepuisDevisAction).
 */
class AccepterDevisAction
{
    public function handle(Devis $devis): Devis
    {
        return DB::transaction(function () use ($devis) {
            $devis = Devis::query()->whereKey($devis->getKey())->lockForUpdate()->firstOrFail();

            if (! $devis->statut->peutTransitionnerVers(StatutDevis::Accepte)) {
                throw new LogicException("Impossible d'accepter un devis au statut [{$devis->statut->value}].");
            }

            $devis->forceFill(['statut' => StatutDevis::Accepte])->save();

            return $devis;
        });
    }
}
