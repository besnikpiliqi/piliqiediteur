<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Services\DocumentNumberingService;
use Illuminate\Support\Facades\DB;
use LogicException;

class EmettreDevisAction
{
    public function __construct(private readonly DocumentNumberingService $numerotation) {}

    public function handle(Devis $devis): Devis
    {
        return DB::transaction(function () use ($devis) {
            $devis = Devis::query()->whereKey($devis->getKey())->lockForUpdate()->firstOrFail();

            if (! $devis->statut->peutTransitionnerVers(StatutDevis::Envoye)) {
                throw new LogicException("Impossible d'envoyer un devis au statut [{$devis->statut->value}].");
            }

            if ($devis->lignes()->doesntExist()) {
                throw new LogicException('Impossible d\'envoyer un devis sans lignes.');
            }

            $numero = $this->numerotation->suivant($devis->magasin, TypeDocument::Devis, now()->format('Y'));

            $devis->forceFill([
                'numero' => $numero,
                'statut' => StatutDevis::Envoye,
                'date_emission' => now(),
            ])->save();

            return $devis;
        });
    }
}
