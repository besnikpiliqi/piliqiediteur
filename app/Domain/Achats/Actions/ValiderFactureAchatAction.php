<?php

namespace App\Domain\Achats\Actions;

use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Events\FactureAchatValidee;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Services\DocumentNumberingService;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Même logique que EmettreFactureAction côté vente : la facture d'achat ne
 * reçoit son numéro interne qu'à la validation, moment où le stock est
 * incrémenté (voir FactureAchatValidee/IncrementerStockApresValidationFactureAchat).
 * Chacun de ses produits doit encore être au catalogue : une réception ne
 * se perd jamais en silence. Un magasin suspendu ou fermé ne valide plus
 * d'achat (voir Magasin::verifierQuIlEstOuvert()).
 */
class ValiderFactureAchatAction
{
    public function __construct(private readonly DocumentNumberingService $numerotation) {}

    /**
     * @throws LogicException
     */
    public function handle(FactureAchat $factureAchat): FactureAchat
    {
        return DB::transaction(function () use ($factureAchat) {
            $factureAchat = FactureAchat::query()->whereKey($factureAchat->getKey())->lockForUpdate()->firstOrFail();
            $factureAchat->magasin->verifierQuIlEstOuvert();

            if (! $factureAchat->statut->peutTransitionnerVers(StatutFactureAchat::Validee)) {
                throw new LogicException("Impossible de valider une facture d'achat au statut [{$factureAchat->statut->value}].");
            }

            if (($produitArchive = $factureAchat->produitArchive()) !== null) {
                throw new LogicException("Le produit « {$produitArchive->nom} » a été archivé : restaurez-le depuis le catalogue, ou retirez-le de la facture, avant de la valider.");
            }

            $numero = $this->numerotation->suivant($factureAchat->magasin, TypeDocument::FactureAchat, now()->format('Y'));

            $factureAchat->forceFill([
                'numero' => $numero,
                'statut' => StatutFactureAchat::Validee,
                'date_validation' => now(),
            ])->save();

            // Synchrone et dans la même transaction, voir le commentaire de
            // FactureAchatValidee.
            FactureAchatValidee::dispatch($factureAchat);

            return $factureAchat;
        });
    }
}
