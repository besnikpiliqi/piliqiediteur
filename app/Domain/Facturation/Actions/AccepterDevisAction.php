<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Models\Devis;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * StatutDevis::Accepte existait depuis la Phase 3 mais n'était atteint par
 * aucun code : un devis envoyé restait "envoyé" indéfiniment, même après
 * acceptation verbale/écrite du client. Nécessaire pour conditionner la
 * conversion en facture (voir CreerFactureDepuisDevisAction) à une
 * acceptation explicite plutôt qu'à un simple envoi.
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
