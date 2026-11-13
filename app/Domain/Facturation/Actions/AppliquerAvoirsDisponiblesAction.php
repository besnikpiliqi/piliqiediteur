<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\RegularisationDUneFacture;
use App\Domain\Tenancy\Support\ValeurRequise;
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
 *
 * Le crédit de l'avoir de régularisation d'une facture, après la
 * correction du SIREN de la société, n'est pas un crédit disponible : il
 * est réservé à cette facture, puis à sa facture rectificative (voir
 * RegularisationDUneFacture), et il ne règle jamais une autre facture.
 * Émise après cet avoir, la rectificative reçoit d'abord ce qui lui en
 * reste, puis, comme toute facture, le crédit disponible du client. Émise
 * avant, elle attend cet avoir (voir
 * StatutFacture::EnAttenteDeRegularisation) et ne reçoit rien ici : tant
 * qu'elle attend, aucun crédit du client ne la règle, et c'est l'avoir qui
 * la règle à son émission (voir ImputationDuCredit). Quel que soit l'ordre
 * des deux gestes, la rectificative reçoit ainsi ce que la facture avait
 * reçu, et reste due de ce que le client devait encore sur la facture.
 * Cette imputation n'est jamais un encaissement.
 */
class AppliquerAvoirsDisponiblesAction
{
    public function __construct(
        private readonly CalculateurLigne $calculateur,
        private readonly RegularisationDUneFacture $regularisation,
    ) {}

    public function handle(Facture $facture): void
    {
        DB::transaction(function () use ($facture) {
            $facture = Facture::query()->whereKey($facture->getKey())->lockForUpdate()->firstOrFail();
            $client = ValeurRequise::de($facture->client, 'le client de la facture');
            $resteDu = $facture->resteDuEnCentimes();

            if ($resteDu <= 0 || $client->estComptoir() || ! $facture->statut->attendUnReglement()) {
                return;
            }

            $reserve = $facture->estUneRectificative() ? $this->regularisation->avoirDeRegularisation($facture->factureRectifiee()->firstOrFail(), verrouille: true) : null;
            $avoirs = collect([$reserve])->filter()->concat(Avoir::query()
                ->where('client_id', $facture->client_id)
                ->where('statut', StatutAvoir::Emise)
                ->whereNull('motif_regularisation')
                ->orderBy('date_emission')
                ->orderBy('id')
                ->lockForUpdate()
                ->get());
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
