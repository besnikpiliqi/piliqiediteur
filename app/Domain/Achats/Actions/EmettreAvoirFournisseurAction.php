<?php

namespace App\Domain\Achats\Actions;

use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Achats\Events\AvoirFournisseurEmis;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Services\CalculateurLigne;
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
    public function __construct(
        private readonly DocumentNumberingService $numerotation,
        private readonly CalculateurLigne $calculateur,
    ) {}

    public function handle(AvoirFournisseur $avoirFournisseur): AvoirFournisseur
    {
        return DB::transaction(function () use ($avoirFournisseur) {
            $avoirFournisseur = AvoirFournisseur::query()->whereKey($avoirFournisseur->getKey())->lockForUpdate()->firstOrFail();

            if (! $avoirFournisseur->statut->peutTransitionnerVers(StatutAvoirFournisseur::Emise)) {
                throw new LogicException("Impossible d'émettre un avoir fournisseur au statut [{$avoirFournisseur->statut->value}].");
            }

            $this->arreterLesMontants($avoirFournisseur);
            $avoirFournisseur->verifierQueLeStockSuffit();

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

    /**
     * Un brouillon n'a encore aucune existence : entre sa création et son
     * émission, un autre avoir fournisseur a pu retourner les mêmes lignes,
     * ou le stock a pu être vendu. Ses quantités sont donc revérifiées et
     * ses montants arrêtés au moment de l'émission, sur le cumul des retours
     * déjà émis (voir LigneAchat::montantsDuRetour()).
     *
     * @throws LogicException
     */
    private function arreterLesMontants(AvoirFournisseur $avoirFournisseur): void
    {
        $lignesArretees = [];

        foreach ($avoirFournisseur->lignes as $ligneAvoir) {
            $ligneAchat = LigneAchat::query()
                ->with('produit')
                ->whereKey($ligneAvoir->ligne_achat_id)
                ->lockForUpdate()
                ->firstOrFail();

            $restante = $ligneAchat->quantiteRetournableRestante();

            if ($ligneAvoir->quantite > $restante) {
                throw new LogicException("La ligne \"{$ligneAchat->description}\" a été retournée ou vendue entre-temps : il ne reste que {$restante} article(s) à retourner.");
            }

            $ligneAvoir->forceFill($ligneAchat->montantsDuRetour($ligneAvoir->quantite))->save();
            $lignesArretees[] = $ligneAvoir->only(['montant_ht', 'montant_tva', 'montant_ttc']);
        }

        $avoirFournisseur->forceFill($this->calculateur->totaliser($lignesArretees));
    }
}
