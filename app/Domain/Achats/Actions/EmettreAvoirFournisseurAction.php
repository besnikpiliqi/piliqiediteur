<?php

namespace App\Domain\Achats\Actions;

use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Achats\Events\AvoirFournisseurEmis;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Services\DocumentNumberingService;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Même logique que EmettreAvoirAction côté vente : l'avoir fournisseur ne
 * reçoit son numéro qu'à l'émission, moment où le stock est décrémenté
 * (voir AvoirFournisseurEmis/DecrementerStockApresEmissionAvoirFournisseur).
 */
class EmettreAvoirFournisseurAction
{
    public function __construct(private readonly DocumentNumberingService $numerotation) {}

    public function handle(AvoirFournisseur $avoirFournisseur): AvoirFournisseur
    {
        return DB::transaction(function () use ($avoirFournisseur) {
            $avoirFournisseur = AvoirFournisseur::query()->whereKey($avoirFournisseur->getKey())->lockForUpdate()->firstOrFail();

            if (! $avoirFournisseur->statut->peutTransitionnerVers(StatutAvoirFournisseur::Emise)) {
                throw new LogicException("Impossible d'émettre un avoir fournisseur au statut [{$avoirFournisseur->statut->value}].");
            }

            $numero = $this->numerotation->suivant($avoirFournisseur->magasin, TypeDocument::AvoirFournisseur, now()->format('Y'));

            $avoirFournisseur->forceFill([
                'numero' => $numero,
                'statut' => StatutAvoirFournisseur::Emise,
                'date_emission' => now(),
            ])->save();

            // Synchrone et dans la même transaction, voir le commentaire de
            // AvoirFournisseurEmis.
            AvoirFournisseurEmis::dispatch($avoirFournisseur);

            return $avoirFournisseur;
        });
    }
}
