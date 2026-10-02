<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Events\FactureEmise;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\DocumentNumberingService;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * L'émission est le SEUL moment où une facture reçoit son numéro (voir
 * ta propre règle métier : on ne numérote jamais un brouillon). Elle est
 * donc aussi le seul moment où le stock est impacté et où la transmission
 * PDP peut se déclencher -- avant l'émission, une facture brouillon n'a
 * aucune existence légale ni commerciale.
 */
class EmettreFactureAction
{
    public function __construct(private readonly DocumentNumberingService $numerotation) {}

    public function handle(Facture $facture): Facture
    {
        return DB::transaction(function () use ($facture) {
            $facture = Facture::query()->whereKey($facture->getKey())->lockForUpdate()->firstOrFail();

            if (! $facture->statut->peutTransitionnerVers(StatutFacture::Emise)) {
                throw new LogicException("Impossible d'émettre une facture au statut [{$facture->statut->value}].");
            }

            if ($facture->lignes()->doesntExist()) {
                throw new LogicException('Impossible d\'émettre une facture sans lignes.');
            }

            $numero = $this->numerotation->suivant($facture->magasin, TypeDocument::Facture, now()->format('Y'));

            $facture->forceFill([
                'numero' => $numero,
                'statut' => StatutFacture::Emise,
                'date_emission' => now(),
            ])->save();

            // Synchrone et dans la même transaction : voir le commentaire
            // de FactureEmise sur pourquoi ce event n'est pas différé.
            FactureEmise::dispatch($facture);

            return $facture;
        });
    }
}
