<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Un avoir est toujours un crédit TOTAL d'une facture déjà émise : ses
 * lignes sont un miroir exact de celles de la facture (mêmes quantités,
 * mêmes montants déjà figés), jamais une saisie libre ni un crédit
 * partiel -- ce dernier cas (créditer une seule ligne, ou une quantité
 * partielle) est un besoin réel mais plus complexe à auditer, laissé pour
 * une itération future si un client l'exige.
 */
class CreerAvoirAction
{
    public function handle(Facture $facture, ?string $motif = null): Avoir
    {
        return DB::transaction(function () use ($facture, $motif) {
            $facture = Facture::query()->whereKey($facture->getKey())->lockForUpdate()->firstOrFail();

            if (in_array($facture->statut, [StatutFacture::Brouillon, StatutFacture::Annulee], true)) {
                throw new LogicException("Impossible de créer un avoir sur une facture au statut [{$facture->statut->value}].");
            }

            if ($facture->possedeUnAvoirEmis()) {
                throw new LogicException("La facture {$facture->numero} a déjà fait l'objet d'un avoir.");
            }

            $avoir = Avoir::create([
                'facture_id' => $facture->id,
                'motif' => $motif,
            ]);

            foreach ($facture->lignes as $ligne) {
                $avoir->lignes()->create([
                    'ligne_facture_id' => $ligne->id,
                    'produit_id' => $ligne->produit_id,
                    'description' => $ligne->description,
                    'reference' => $ligne->reference,
                    'quantite' => $ligne->quantite,
                    'prix_unitaire_ht' => $ligne->prix_unitaire_ht,
                    'taux_tva' => $ligne->taux_tva,
                    'remise_pourcentage' => $ligne->remise_pourcentage,
                    'montant_ht' => $ligne->montant_ht,
                    'montant_tva' => $ligne->montant_tva,
                    'montant_ttc' => $ligne->montant_ttc,
                    'ordre' => $ligne->ordre,
                ]);
            }

            $avoir->forceFill([
                'total_ht' => $facture->total_ht,
                'total_tva' => $facture->total_tva,
                'total_ttc' => $facture->total_ttc,
            ])->save();

            return $avoir->fresh('lignes');
        });
    }
}
