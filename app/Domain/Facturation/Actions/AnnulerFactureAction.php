<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Events\FactureAnnulee;
use App\Domain\Facturation\Models\Facture;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Une facture "payee" ou "partiellement_payee" ne peut PAS être annulée
 * ici (voir StatutFacture::transitionsAutorisees) : de l'argent a déjà
 * été encaissé, la correction légale passe par un avoir (voir Avoir), pas
 * par une annulation qui ferait disparaître la trace comptable.
 */
class AnnulerFactureAction
{
    public function handle(Facture $facture, ?string $motif = null): Facture
    {
        return DB::transaction(function () use ($facture, $motif) {
            $facture = Facture::query()->whereKey($facture->getKey())->lockForUpdate()->firstOrFail();

            if (! $facture->statut->peutTransitionnerVers(StatutFacture::Annulee)) {
                throw new LogicException("Impossible d'annuler une facture au statut [{$facture->statut->value}].");
            }

            $statutAvantAnnulation = $facture->statut;

            $facture->forceFill([
                'statut' => StatutFacture::Annulee,
                'notes' => $motif !== null
                    ? trim(($facture->notes ?? '')."\nAnnulée : {$motif}")
                    : $facture->notes,
            ])->save();

            // Si la facture avait déjà été émise, son stock a déjà été
            // décrémenté (voir DecrementerStockApresEmissionFacture) : il
            // faut le restaurer. Un brouillon annulé n'a jamais touché au
            // stock, rien à compenser.
            if ($statutAvantAnnulation !== StatutFacture::Brouillon) {
                FactureAnnulee::dispatch($facture);
            }

            return $facture;
        });
    }
}
