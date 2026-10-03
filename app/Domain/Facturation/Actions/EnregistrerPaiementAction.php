<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\Paiement;
use App\Domain\Facturation\Models\PaiementAllocation;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Un paiement peut régler plusieurs factures du même client en une seule
 * fois (ex : un virement qui solde trois factures d'un coup). Chaque
 * allocation est validée contre le solde réellement restant dû de la
 * facture concernée, avec verrouillage pessimiste pour empêcher qu'un
 * double encaissement concurrent ne fasse dépasser le total dû. Un
 * paiement n'a pas à être intégralement alloué dès sa création (voir
 * Paiement::montantNonAlloue) : le solde non affecté pourra être imputé
 * plus tard sur une facture future.
 */
class EnregistrerPaiementAction
{
    /**
     * @param  array{client_id: int, mode_paiement: string, montant: float, reference?: ?string, notes?: ?string, recu_le: string}  $donneesPaiement
     * @param  array<int, array{facture_id: int, montant: float}>  $allocations
     */
    public function handle(array $donneesPaiement, array $allocations): Paiement
    {
        return DB::transaction(function () use ($donneesPaiement, $allocations) {
            $paiement = Paiement::create($donneesPaiement);

            $totalAlloue = 0.0;

            foreach ($allocations as $allocation) {
                $montantAlloue = round((float) $allocation['montant'], 2);

                if ($montantAlloue <= 0) {
                    throw new LogicException('Le montant alloué à une facture doit être strictement positif.');
                }

                $facture = Facture::query()->whereKey($allocation['facture_id'])->lockForUpdate()->firstOrFail();

                if ($facture->client_id !== $paiement->client_id) {
                    throw new LogicException("La facture {$facture->numero} n'appartient pas au client de ce paiement.");
                }

                if (! in_array($facture->statut, [
                    StatutFacture::Emise,
                    StatutFacture::Envoyee,
                    StatutFacture::PartiellementPayee,
                    StatutFacture::EnRetard,
                ], true)) {
                    throw new LogicException("Impossible d'encaisser un paiement sur une facture au statut [{$facture->statut->value}].");
                }

                $montantRestantAvant = $facture->montantRestant();

                if ($montantAlloue > $montantRestantAvant) {
                    throw new LogicException("Le montant alloué ({$montantAlloue}) dépasse le solde restant dû ({$montantRestantAvant}) de la facture {$facture->numero}.");
                }

                $totalAlloue = round($totalAlloue + $montantAlloue, 2);

                if ($totalAlloue > (float) $paiement->montant) {
                    throw new LogicException('La somme des allocations dépasse le montant du paiement.');
                }

                PaiementAllocation::create([
                    'paiement_id' => $paiement->id,
                    'facture_id' => $facture->id,
                    'montant_alloue' => $montantAlloue,
                ]);

                $nouveauStatut = $montantRestantAvant - $montantAlloue <= 0.0
                    ? StatutFacture::Payee
                    : StatutFacture::PartiellementPayee;

                if ($facture->statut !== $nouveauStatut) {
                    if (! $facture->statut->peutTransitionnerVers($nouveauStatut)) {
                        throw new LogicException("Transition de statut invalide sur la facture {$facture->numero} : [{$facture->statut->value}] -> [{$nouveauStatut->value}].");
                    }

                    $facture->forceFill(['statut' => $nouveauStatut])->save();
                }
            }

            return $paiement;
        });
    }
}
