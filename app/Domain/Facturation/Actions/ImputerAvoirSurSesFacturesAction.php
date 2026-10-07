<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Facturation\Services\CalculateurLigne;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Un avoir modifie la facture qu'il corrige (art. 289 I-5 du CGI) : à son
 * émission, il réduit d'abord ce qui reste à payer sur chacune de ses
 * factures, à hauteur de ses propres lignes sur cette facture. Le client
 * ne doit plus que la différence, et une facture en retard ne l'est plus
 * que pour ce reste. Seul l'excédent (facture déjà réglée, ou avoir plus
 * grand que le reste dû) devient un crédit pour la prochaine facture du
 * client (voir AppliquerAvoirsDisponiblesAction).
 */
class ImputerAvoirSurSesFacturesAction
{
    public function __construct(private readonly CalculateurLigne $calculateur) {}

    public function handle(Avoir $avoir): void
    {
        DB::transaction(function () use ($avoir) {
            $creditParFacture = $avoir->lignes()->with('ligneFacture')->get()
                ->groupBy(fn (LigneAvoir $ligne): int => (int) $ligne->ligneFacture->facture_id)
                ->map(fn (Collection $lignes): float => $this->calculateur->sommer($lignes->pluck('montant_ttc')));

            foreach ($creditParFacture as $factureId => $credit) {
                $facture = Facture::query()->whereKey($factureId)->lockForUpdate()->firstOrFail();

                if (! $facture->statut->attendUnReglement()) {
                    continue;
                }

                $montantImpute = min($credit, $facture->montantRestant());

                if ($montantImpute <= 0) {
                    continue;
                }

                AvoirAllocation::create([
                    'avoir_id' => $avoir->id,
                    'facture_id' => $facture->id,
                    'montant_alloue' => $montantImpute,
                ]);

                $facture->constaterUnReglement();
            }
        });
    }
}
