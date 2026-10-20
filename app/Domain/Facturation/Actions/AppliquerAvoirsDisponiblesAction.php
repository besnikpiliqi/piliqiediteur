<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Tva\Services\CalculateurLigne;
use Illuminate\Support\Facades\DB;

/**
 * À l'émission d'une facture (voir EmettreFactureAction), le crédit
 * d'avoir disponible de son client (voir Client::soldeCrediteurEnCentimes())
 * règle ce qui en reste dû, dans la transaction qui la signe. Ce crédit
 * est ce qu'un avoir n'a pu imputer à son émission, ni la contre-passation
 * d'un paiement sur ce qu'elle a rouvert, le client n'ayant plus aucune
 * facture due (voir ImputationDuCredit). Un avoir n'est jamais remboursé
 * (B5). À la caisse, il s'affiche comme la part « Avoir (crédit du
 * client) » du règlement, remplie d'office (voir ApercuDuReglement).
 *
 * Les avoirs les plus anciens sont consommés en premier, et peuvent se
 * répartir sur plusieurs factures successives comme plusieurs avoirs sur
 * une même facture si le crédit d'un seul n'y suffit pas. Tout se compte
 * en centimes : ce qui reste dû de la facture, relue verrouillée, et le
 * crédit de chaque avoir, relu verrouillé.
 *
 * Jamais pour le client comptoir : le crédit d'un avoir fait au comptoir
 * n'appartient qu'à qui en présente le numéro, son bon d'avoir (voir
 * EmettreFactureAction), jamais à la vente comptoir suivante.
 */
class AppliquerAvoirsDisponiblesAction
{
    public function __construct(private readonly CalculateurLigne $calculateur) {}

    public function handle(Facture $facture): void
    {
        DB::transaction(function () use ($facture) {
            $facture = Facture::query()->whereKey($facture->getKey())->lockForUpdate()->firstOrFail();
            /** @var Client $client */
            $client = $facture->client;
            $resteDu = $facture->resteDuEnCentimes();

            if ($resteDu <= 0 || $client->estComptoir()) {
                return;
            }

            $avoirs = Avoir::query()
                ->where('client_id', $facture->client_id)
                ->where('statut', StatutAvoir::Emise)
                ->orderBy('date_emission')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $impute = 0;

            foreach ($avoirs as $avoir) {
                if ($impute === $resteDu) {
                    break;
                }

                $montant = min($avoir->creditEnCentimes(), $resteDu - $impute);

                if ($montant <= 0) {
                    continue;
                }

                AvoirAllocation::create([
                    'avoir_id' => $avoir->id,
                    'facture_id' => $facture->id,
                    'montant_alloue' => $this->calculateur->enDecimal($montant),
                ]);

                $impute += $montant;
            }

            // Rien n'a été crédité (aucun avoir disponible pour ce client) :
            // ne JAMAIS toucher au statut dans ce cas, sous peine de faire
            // passer une facture fraîchement émise et totalement impayée à
            // "partiellement payée" à tort.
            if ($impute > 0) {
                $facture->constaterUnReglement();
            }
        });
    }
}
