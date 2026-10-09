<?php

namespace App\Domain\Facturation\Actions;

use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Events\AvoirEmis;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\DocumentNumberingService;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Même logique que EmettreFactureAction : l'avoir ne reçoit son numéro
 * légal qu'à l'émission, moment où le stock est restauré (voir
 * AvoirEmis/RestaurerStockApresEmissionAvoir).
 */
class EmettreAvoirAction
{
    public function __construct(
        private readonly DocumentNumberingService $numerotation,
        private readonly CalculateurLigne $calculateur,
    ) {}

    public function handle(Avoir $avoir): Avoir
    {
        return DB::transaction(function () use ($avoir) {
            $avoir = Avoir::query()->whereKey($avoir->getKey())->lockForUpdate()->firstOrFail();

            if (! $avoir->statut->peutTransitionnerVers(StatutAvoir::Emise)) {
                throw new LogicException("Impossible d'émettre un avoir au statut [{$avoir->statut->value}].");
            }

            $this->arreterLesMontants($avoir);

            $numero = $this->numerotation->suivant($avoir->magasin, TypeDocument::Avoir, now()->format('Y'));

            $avoir->forceFill([
                'numero' => $numero,
                'statut' => StatutAvoir::Emise,
                'date_emission' => now(),
            ])->save();

            // Synchrone et dans la même transaction, voir le commentaire
            // de AvoirEmis.
            AvoirEmis::dispatch($avoir);

            return $avoir;
        });
    }

    /**
     * Un brouillon n'a encore aucune existence légale : entre sa création
     * et son émission, un autre avoir a pu créditer les mêmes lignes (deux
     * retours saisis en même temps). Ses quantités sont donc revérifiées et
     * ses montants arrêtés au moment où il devient un document fiscal -- le
     * dernier retour d'une ligne en prend alors le solde exact (voir
     * LigneFacture::montantsDuRetour()).
     *
     * @throws LogicException
     */
    private function arreterLesMontants(Avoir $avoir): void
    {
        $lignesArretees = [];

        foreach ($avoir->lignes as $ligneAvoir) {
            $ligneFacture = LigneFacture::query()
                ->whereKey($ligneAvoir->ligne_facture_id)
                ->lockForUpdate()
                ->firstOrFail();

            $restante = $ligneFacture->quantiteCreditableRestante();

            if ($ligneAvoir->quantite > $restante) {
                throw new LogicException("La ligne \"{$ligneFacture->description}\" a été créditée entre-temps par un autre avoir : il ne reste que {$restante} article(s) à créditer.");
            }

            $ligneAvoir->forceFill($ligneFacture->montantsDuRetour($ligneAvoir->quantite))->save();
            $lignesArretees[] = $ligneAvoir->only(['montant_ht', 'montant_tva', 'montant_ttc']);
        }

        $avoir->forceFill($this->calculateur->totaliser($lignesArretees));
    }
}
