<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Copie telle quelle les lignes déjà figées du devis accepté vers une
 * nouvelle facture BROUILLON (jamais émise automatiquement : l'émission
 * reste une étape manuelle distincte, voir EmettreFactureAction) -- aucun
 * recalcul de prix, le devis a déjà figé ses montants au moment de sa
 * propre émission, exactement comme CreerAvoirAction fait un miroir de la
 * facture plutôt que de reconstruire via ConstructeurLigneDocument.
 */
class CreerFactureDepuisDevisAction
{
    public function handle(Devis $devis): Facture
    {
        return DB::transaction(function () use ($devis) {
            $devis = Devis::query()->whereKey($devis->getKey())->lockForUpdate()->firstOrFail();

            if ($devis->statut !== StatutDevis::Accepte) {
                throw new LogicException("Seul un devis accepté peut être converti en facture (statut actuel : [{$devis->statut->value}]).");
            }

            if ($devis->factures()->exists()) {
                throw new LogicException('Ce devis a déjà été converti en facture.');
            }

            $facture = Facture::create([
                'client_id' => $devis->client_id,
                'devis_id' => $devis->id,
                'devise' => $devis->devise,
                'conditions_paiement' => $devis->conditions_paiement,
            ]);

            foreach ($devis->lignes as $ligne) {
                $facture->lignes()->create([
                    'produit_id' => $ligne->produit_id,
                    'description' => $ligne->description,
                    'reference' => $ligne->reference,
                    'quantite' => $ligne->quantite,
                    'prix_unitaire_ht' => $ligne->prix_unitaire_ht,
                    'prix_unitaire_ttc' => $ligne->prix_unitaire_ttc,
                    'taux_tva' => $ligne->taux_tva,
                    'remise_pourcentage' => $ligne->remise_pourcentage,
                    'montant_ht' => $ligne->montant_ht,
                    'montant_tva' => $ligne->montant_tva,
                    'montant_ttc' => $ligne->montant_ttc,
                    'ordre' => $ligne->ordre,
                ]);
            }

            $facture->forceFill([
                'total_ht' => $devis->total_ht,
                'total_tva' => $devis->total_tva,
                'total_ttc' => $devis->total_ttc,
            ])->save();

            return $facture->fresh('lignes');
        });
    }
}
