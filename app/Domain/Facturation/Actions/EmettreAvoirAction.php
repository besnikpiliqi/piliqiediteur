<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Events\AvoirEmis;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Services\DocumentNumberingService;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Même logique que EmettreFactureAction : l'avoir ne reçoit son numéro
 * légal qu'à l'émission, moment où le stock est restauré (voir
 * AvoirEmis/RestaurerStockApresEmissionAvoir).
 */
class EmettreAvoirAction
{
    public function __construct(private readonly DocumentNumberingService $numerotation) {}

    public function handle(Avoir $avoir): Avoir
    {
        return DB::transaction(function () use ($avoir) {
            $avoir = Avoir::query()->whereKey($avoir->getKey())->lockForUpdate()->firstOrFail();

            if (! $avoir->statut->peutTransitionnerVers(StatutAvoir::Emise)) {
                throw new LogicException("Impossible d'émettre un avoir au statut [{$avoir->statut->value}].");
            }

            $numero = $this->numerotation->suivant($avoir->magasin, TypeDocument::Avoir, now()->format('Y'));

            $avoir->forceFill([
                'numero' => $numero,
                'statut' => StatutAvoir::Emise,
                'date_emission' => now(),
            ])->save();

            // Synchrone et dans la même transaction, voir le commentaire
            // de AvoirEmis.
            AvoirEmis::dispatch($avoir);

            return $avoir;
        });
    }
}
