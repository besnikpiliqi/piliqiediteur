<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Facturation\Services\CalculateurLigne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * À son émission (voir EmettreAvoirAction, sous le verrou du registre
 * fiscal et avant sa signature), un avoir impute son crédit sur ce qui
 * reste dû des factures de son client :
 *
 * 1. il modifie d'abord les factures qu'il corrige (art. 289 I-5 du CGI) :
 *    chacune voit ce qui en reste dû réduit, au plus, de ses propres lignes
 *    de l'avoir ;
 * 2. ce qu'il n'a pas pu y imputer (facture déjà réglée, ou avoir plus
 *    grand que ce qui en restait dû) règle ensuite les factures émises de
 *    son client qui attendent un règlement, celles qu'il corrige comprises,
 *    de la plus ancienne à la plus récente (B6) : un règlement, jamais une
 *    correction de leur TVA ;
 * 3. le reliquat reste un crédit, que la prochaine facture du client
 *    absorbe à son émission (voir AppliquerAvoirsDisponiblesAction) ou
 *    qu'un paiement impute (voir EnregistrerPaiementAction). Un avoir n'est
 *    jamais remboursé (B5).
 *
 * Jamais d'étape 2 pour le client comptoir, que partagent toutes les
 * ventes anonymes du magasin : le crédit d'un avoir fait au comptoir
 * n'appartient qu'à qui en présente le numéro, son bon d'avoir (voir
 * EnregistrerVenteCaisseAction).
 *
 * Chaque facture reçoit au plus une imputation de l'avoir, son montant
 * des deux étapes réunies, puis son statut suit ce qui en reste dû : une
 * facture en retard le reste tant qu'il en reste un montant dû (G1, voir
 * Facture::constaterUnReglement()). Ces imputations naissent dans la
 * transaction de l'émission, et l'avoir les signe (voir ContenuFiscal).
 * Les factures se relisent sous le verrou du registre que tient
 * l'émission, après les paiements et les avoirs validés avant lui, et par
 * MagasinScope : une facture d'un autre magasin n'est jamais imputée, même
 * si son client_id désignait ce client.
 */
class ImputerAvoirSurSesFacturesAction
{
    public function __construct(private readonly CalculateurLigne $calculateur) {}

    public function handle(Avoir $avoir): void
    {
        DB::transaction(function () use ($avoir) {
            $imputations = $this->imputationsSurSesFactures($avoir);
            $reste = $this->calculateur->soustraire($avoir->total_ttc, ...array_column($imputations, 'montant'));

            if ($reste > 0 && ! $avoir->client->estComptoir()) {
                $imputations = $this->imputerLeResteSurLesFacturesDuClient($avoir, $reste, $imputations);
            }

            foreach ($imputations as ['facture' => $facture, 'montant' => $montant]) {
                AvoirAllocation::create([
                    'avoir_id' => $avoir->id,
                    'facture_id' => $facture->id,
                    'montant_alloue' => $montant,
                ]);

                $facture->constaterUnReglement();
            }
        });
    }

    /**
     * Ce que l'avoir impute sur chacune des factures qu'il corrige, relue
     * verrouillée : ses propres lignes sur cette facture, au plus ce qui en
     * reste dû, rien sur une facture qui n'attend plus de règlement.
     *
     * @return array<int, array{facture: Facture, montant: float}>
     */
    private function imputationsSurSesFactures(Avoir $avoir): array
    {
        $creditParFacture = $avoir->lignes()->with('ligneFacture')->get()
            ->groupBy(fn (LigneAvoir $ligne): int => (int) $ligne->ligneFacture->facture_id)
            ->map(fn (Collection $lignes): float => $this->calculateur->sommer($lignes->pluck('montant_ttc')));
        $imputations = [];

        foreach ($creditParFacture as $factureId => $credit) {
            $facture = Facture::query()->whereKey($factureId)->lockForUpdate()->firstOrFail();
            $montant = $facture->statut->attendUnReglement() ? min($credit, $facture->montantRestant()) : 0.0;

            if ($montant > 0) {
                $imputations[$facture->id] = ['facture' => $facture, 'montant' => $montant];
            }
        }

        return $imputations;
    }

    /**
     * Le reste de l'avoir règle les factures émises du client qui attendent
     * un règlement, relues verrouillées, de la plus ancienne à la plus
     * récente : chacune reçoit ce qui en reste dû après l'imputation de
     * l'étape 1, jusqu'à épuisement du reste.
     *
     * @param  array<int, array{facture: Facture, montant: float}>  $imputations
     * @return array<int, array{facture: Facture, montant: float}>
     */
    private function imputerLeResteSurLesFacturesDuClient(Avoir $avoir, float $reste, array $imputations): array
    {
        $factures = Facture::query()
            ->where('client_id', $avoir->client_id)
            ->whereIn('statut', StatutFacture::quiAttendentUnReglement())
            ->orderBy('date_emission')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($factures as $facture) {
            if ($reste <= 0) {
                break;
            }

            $dejaImpute = $imputations[$facture->id]['montant'] ?? 0.0;
            $montant = min($reste, $this->calculateur->soustraire($facture->montantRestant(), $dejaImpute));

            if ($montant <= 0) {
                continue;
            }

            $imputations[$facture->id] = [
                'facture' => $imputations[$facture->id]['facture'] ?? $facture,
                'montant' => $this->calculateur->sommer([$dejaImpute, $montant]),
            ];
            $reste = $this->calculateur->soustraire($reste, $montant);
        }

        return $imputations;
    }
}
