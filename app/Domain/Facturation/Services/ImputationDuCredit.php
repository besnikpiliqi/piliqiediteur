<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Tva\Services\CalculateurLigne;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Les deux étapes de l'imputation d'un crédit d'avoir sur ce qui reste dû
 * des factures de son client, dans le même ordre à l'émission d'un avoir
 * (voir ImputerAvoirSurSesFacturesAction) et à la contre-passation d'un
 * paiement (complément 1, voir ContrePasserPaiementAction) :
 *
 * 1. chaque avoir modifie d'abord les factures qu'il corrige (art. 289 I-5
 *    du CGI), dans l'ordre de ses lignes : chacune voit ce qui en reste dû
 *    réduit, au plus, de ses propres lignes de l'avoir moins ce qu'il y a
 *    déjà imputé ;
 * 2. ce que les avoirs n'ont pas pu y imputer règle ensuite les factures
 *    émises du client qui attendent un règlement, de la plus ancienne à la
 *    plus récente, les avoirs les plus anciens d'abord (B6) : un
 *    règlement, jamais une correction de leur TVA. Jamais pour le client
 *    comptoir, que partagent toutes les ventes anonymes du magasin : le
 *    crédit d'un avoir fait au comptoir n'appartient qu'à qui en présente
 *    le numéro, son bon d'avoir, et ne s'impute d'office que sur les
 *    tickets qu'il corrige.
 *
 * Chaque facture reçoit au plus une imputation de chaque avoir, son
 * montant des deux étapes réunies, en centimes. Les factures et les avoirs
 * se relisent verrouillés, sous le verrou du registre fiscal que tient
 * l'action qui les impute, après les paiements et les avoirs validés avant
 * elle, et par MagasinScope : une facture ou un avoir d'un autre magasin
 * n'est jamais imputé, même si son client_id désignait ce client. Les
 * imputations elles-mêmes, en ajout seul, se lisent sans verrou.
 */
class ImputationDuCredit
{
    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * Ce qu'un avoir, à son émission, impute de son crédit (tout son total
     * TTC).
     *
     * @return list<array{avoir: Avoir, facture: Facture, montant: int}>
     */
    public function aLEmissionDe(Avoir $avoir): array
    {
        /** @var Client $client */
        $client = $avoir->client;

        return $this->imputations($client, collect([$avoir]));
    }

    /**
     * Ce que le crédit encore disponible des avoirs émis du client impute
     * sur ce que vient de rouvrir la contre-passation d'un paiement, et sur
     * ses autres factures dues. Pour le client comptoir, seuls comptent les
     * avoirs qui corrigent l'une de ses factures dues.
     *
     * @return list<array{avoir: Avoir, facture: Facture, montant: int}>
     */
    public function apresUneContrePassation(Client $client): array
    {
        $facturesDues = Facture::query()
            ->where('client_id', $client->getKey())
            ->whereIn('statut', StatutFacture::quiAttendentUnReglement())
            ->pluck('id');

        if ($facturesDues->isEmpty()) {
            return [];
        }

        $avoirs = Avoir::query()
            ->where('client_id', $client->getKey())
            ->where('statut', StatutAvoir::Emise)
            ->when($client->estComptoir(), fn (Builder $avoirs): Builder => $avoirs->whereHas('lignes.ligneFacture', fn (Builder $lignes): Builder => $lignes->whereIn('facture_id', $facturesDues)))
            ->orderBy('date_emission')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return $this->imputations($client, $avoirs);
    }

    /**
     * @param  Collection<int, Avoir>  $avoirs  du plus ancien au plus récent
     * @return list<array{avoir: Avoir, facture: Facture, montant: int}>
     */
    private function imputations(Client $client, Collection $avoirs): array
    {
        $credits = $avoirs->mapWithKeys(fn (Avoir $avoir): array => [$avoir->getKey() => $avoir->creditEnCentimes()])->all();
        $restes = [];
        $imputations = [];
        $imputer = function (Avoir $avoir, Facture $facture, int $plafond) use (&$credits, &$restes, &$imputations): void {
            $restes[$facture->getKey()] ??= $facture->statut->attendUnReglement() ? $facture->resteDuEnCentimes() : 0;
            $montant = min($credits[$avoir->getKey()], $plafond, $restes[$facture->getKey()]);

            if ($montant <= 0) {
                return;
            }

            $cle = $avoir->getKey().':'.$facture->getKey();
            $imputations[$cle] = ['avoir' => $avoir, 'facture' => $imputations[$cle]['facture'] ?? $facture, 'montant' => ($imputations[$cle]['montant'] ?? 0) + $montant];
            $credits[$avoir->getKey()] -= $montant;
            $restes[$facture->getKey()] -= $montant;
        };
        $factures = [];

        foreach ($avoirs as $avoir) {
            foreach ($this->partsSurLesFacturesCorrigees($avoir) as $factureId => $part) {
                $factures[$factureId] ??= Facture::query()->whereKey($factureId)->lockForUpdate()->firstOrFail();
                $imputer($avoir, $factures[$factureId], $part);
            }
        }

        if (! $client->estComptoir() && array_sum($credits) > 0) {
            $facturesDues = Facture::query()
                ->where('client_id', $client->getKey())
                ->whereIn('statut', StatutFacture::quiAttendentUnReglement())
                ->orderBy('date_emission')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($avoirs as $avoir) {
                foreach ($facturesDues as $facture) {
                    $imputer($avoir, $factures[$facture->getKey()] ??= $facture, PHP_INT_MAX);
                }
            }
        }

        return array_values($imputations);
    }

    /**
     * Ce que l'avoir peut encore imputer sur chaque facture qu'il corrige,
     * dans l'ordre de ses lignes, en centimes : le TTC de ses lignes sur
     * cette facture, moins ce qu'il y a déjà imputé, contre-passations
     * déduites.
     *
     * @return array<int, int>
     */
    private function partsSurLesFacturesCorrigees(Avoir $avoir): array
    {
        $dejaImpute = AvoirAllocation::query()
            ->where('avoir_id', $avoir->getKey())
            ->get(['facture_id', 'montant_alloue'])
            ->groupBy('facture_id')
            ->map(fn (Collection $allocations): int => (int) $allocations->sum(fn (AvoirAllocation $allocation): int => $this->calculateur->enCentiemes($allocation->montant_alloue)));

        return $avoir->lignes()->with('ligneFacture')->get()
            ->groupBy(fn (LigneAvoir $ligne): int => (int) $ligne->ligneFacture->facture_id)
            ->map(fn (Collection $lignes, int $factureId): int => (int) $lignes->sum(fn (LigneAvoir $ligne): int => $this->calculateur->enCentiemes($ligne->montant_ttc)) - ($dejaImpute[$factureId] ?? 0))
            ->all();
    }
}
