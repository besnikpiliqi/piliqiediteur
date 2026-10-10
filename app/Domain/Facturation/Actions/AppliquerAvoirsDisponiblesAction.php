<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use Illuminate\Support\Facades\DB;

/**
 * À l'émission d'une facture (voir EmettreFactureAction), le crédit
 * d'avoir disponible de son client (voir Client::soldeCrediteur()) règle
 * ce qui en reste dû, dans la transaction qui la signe. Ce crédit est ce
 * qu'un avoir n'a pu imputer à son émission, le client n'ayant plus
 * aucune facture due (voir ImputerAvoirSurSesFacturesAction), ou ce que
 * la contre-passation d'un paiement a rendu à ses avoirs (voir
 * ContrePasserPaiementAction). Un avoir n'est jamais remboursé (B5).
 *
 * Une contre-passation rouvre aussi les factures que réglait le paiement
 * erroné, sans y imputer ce crédit, qui attend le bon paiement (K9, voir
 * EnregistrerPaiementAction) : une facture émise entre-temps le prend,
 * même si une facture plus ancienne ainsi rouverte reste due.
 *
 * Les avoirs les plus anciens sont consommés en premier, et peuvent se
 * répartir sur plusieurs factures successives comme plusieurs avoirs sur
 * une même facture si le crédit d'un seul n'y suffit pas.
 *
 * Jamais pour le client comptoir : le crédit d'un avoir fait au comptoir
 * n'appartient qu'à qui en présente le numéro, son bon d'avoir (voir
 * EnregistrerVenteCaisseAction), jamais à la vente comptoir suivante.
 */
class AppliquerAvoirsDisponiblesAction
{
    public function __construct(private readonly CalculateurLigne $calculateur) {}

    public function handle(Facture $facture): void
    {
        DB::transaction(function () use ($facture) {
            $facture = Facture::query()->whereKey($facture->getKey())->lockForUpdate()->firstOrFail();

            $montantRestant = $facture->montantRestant();

            if ($montantRestant <= 0) {
                return;
            }

            /** @var Client $client */
            $client = $facture->client;

            if ($client->estComptoir()) {
                return;
            }

            $avoirsDisponibles = Avoir::query()
                ->where('client_id', $facture->client_id)
                ->where('statut', StatutAvoir::Emise)
                ->orderBy('date_emission')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter(fn (Avoir $avoir) => $avoir->montantNonAlloue() > 0);

            $montantTotalApplique = 0.0;

            foreach ($avoirsDisponibles as $avoir) {
                if ($montantRestant <= 0) {
                    break;
                }

                $montantApplique = min($avoir->montantNonAlloue(), $montantRestant);

                AvoirAllocation::create([
                    'avoir_id' => $avoir->id,
                    'facture_id' => $facture->id,
                    'montant_alloue' => $montantApplique,
                ]);

                $montantRestant = $this->calculateur->soustraire($montantRestant, $montantApplique);
                $montantTotalApplique = $this->calculateur->sommer([$montantTotalApplique, $montantApplique]);
            }

            // Rien n'a été crédité (aucun avoir disponible pour ce client) :
            // ne JAMAIS toucher au statut dans ce cas, sous peine de faire
            // passer une facture fraîchement émise et totalement impayée à
            // "partiellement payée" à tort.
            if ($montantTotalApplique <= 0) {
                return;
            }

            $facture->constaterUnReglement();
        });
    }
}
