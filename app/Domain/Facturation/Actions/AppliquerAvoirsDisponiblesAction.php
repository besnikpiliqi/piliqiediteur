<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Applique automatiquement le crédit disponible du client (voir
 * Client::soldeCrediteur()) sur une facture qui vient d'être émise --
 * jamais de remboursement réel (espèces/virement), ce logiciel n'étant pas
 * connecté à une banque : un avoir émis reste un crédit en attente tant
 * qu'aucune nouvelle facture de ce client ne vient l'absorber (voir le
 * docblock de Client::soldeCrediteur()).
 *
 * Les avoirs les plus anciens sont consommés en premier (FIFO), et
 * peuvent se répartir sur plusieurs factures successives comme sur
 * plusieurs avoirs pour une même facture si le crédit d'un seul n'y
 * suffit pas.
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

            // Le client comptoir générique (voir Client::estComptoir()) n'a
            // jamais de solde créditeur : un avoir émis "pour lui" n'a
            // aucune personne identifiable à qui l'appliquer plus tard, et
            // l'appliquer au hasard sur la prochaine vente comptoir
            // reviendrait à donner le remboursement d'une personne à une
            // autre.
            if ($client->estComptoir()) {
                return;
            }

            $avoirsDisponibles = Avoir::query()
                ->where('client_id', $facture->client_id)
                ->where('statut', StatutAvoir::Emise)
                ->orderBy('date_emission')
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

            $nouveauStatut = $montantRestant <= 0.0
                ? StatutFacture::Payee
                : StatutFacture::PartiellementPayee;

            if ($facture->statut === $nouveauStatut) {
                return;
            }

            if (! $facture->statut->peutTransitionnerVers($nouveauStatut)) {
                throw new LogicException("Transition de statut invalide sur la facture {$facture->numero} : [{$facture->statut->value}] -> [{$nouveauStatut->value}].");
            }

            $facture->forceFill(['statut' => $nouveauStatut])->save();
        });
    }
}
