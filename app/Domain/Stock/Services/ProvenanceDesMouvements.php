<?php

namespace App\Domain\Stock\Services;

use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneAvoir;
use App\Domain\Stock\Enums\StatutInventaireOuverture;
use App\Domain\Stock\Enums\TypeMouvementStock;
use App\Domain\Stock\Exceptions\MouvementDeStockInjustifieException;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Stock\Models\MouvementStock;
use App\Domain\Tenancy\Enums\MotifFermeture;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ValeursLues;
use Illuminate\Database\Eloquent\Model;

/**
 * Ce que la base exige de chaque mouvement du grand livre (voir
 * GardesDuStock::poserLaProvenance()), contrôlé d'abord par l'application,
 * sous le verrou du produit, pour refuser par un message en français
 * avant toute écriture : sa source est le document du magasin actif que
 * son type désigne (TypeMouvementStock::justificatif()), relu dans ce
 * magasin, dans l'état voulu, qui porte son produit ; le net signé de
 * tous les mouvements de ce document pour ce produit, celui-ci compris,
 * reste entre zéro et ses lignes, et les égale après un ajustement, qui
 * porte son motif ; il se fait au nom d'une personne, sauf la rentrée
 * d'une cession annulée, que fait la procédure de l'éditeur. La base
 * contrôle en plus, à la validation de la transaction, que cette personne
 * est l'auteur de l'acte qui le justifie (voir
 * GardesDuStock::poserLAuteurDesMouvements()), ce que retour client,
 * cession et rentrée bornent déjà ici (voir StockLedgerService).
 */
class ProvenanceDesMouvements
{
    public function __construct(private readonly OperateurCourant $operateur) {}

    /**
     * @throws MouvementDeStockInjustifieException
     */
    public function exiger(Produit $produitVerrouille, TypeMouvementStock $type, int $quantite, ?Model $source, ?string $note): void
    {
        $document = $this->documentRelu($type, $source);

        if ($type === TypeMouvementStock::EntreeAnnulationCession ? $this->operateur->personnePosee() !== null : $this->operateur->personnePosee() === null) {
            throw new MouvementDeStockInjustifieException(MouvementDeStockInjustifieException::SANS_AUTEUR);
        }

        if ($type->estUnAjustement() && trim((string) $note) === '') {
            throw new MouvementDeStockInjustifieException(MouvementDeStockInjustifieException::SANS_MOTIF);
        }

        if ($document instanceof Magasin) {
            return;
        }

        $plafond = $this->plafond($type, $document, $produitVerrouille);

        if ($plafond === null) {
            throw new MouvementDeStockInjustifieException(MouvementDeStockInjustifieException::SANS_DOCUMENT);
        }

        $sens = $type === TypeMouvementStock::Vente || $type === TypeMouvementStock::RetourFournisseur ? -1 : 1;
        $net = $sens * ($this->variationDuDocument($document, $produitVerrouille) + $type->variation($quantite));

        if ($net < 0 || $net > $plafond || ($type->estUnAjustement() && $net !== $plafond)) {
            throw new MouvementDeStockInjustifieException(MouvementDeStockInjustifieException::AU_DELA_DU_DOCUMENT);
        }
    }

    /**
     * Le document qui justifie le mouvement, relu dans le magasin actif et
     * dans l'état voulu.
     *
     * @throws MouvementDeStockInjustifieException
     */
    private function documentRelu(TypeMouvementStock $type, ?Model $source): Model
    {
        $justificatif = $type->justificatif();

        if (! $source instanceof $justificatif) {
            throw new MouvementDeStockInjustifieException(MouvementDeStockInjustifieException::SANS_DOCUMENT);
        }

        $document = match (true) {
            $source instanceof Magasin => CleDuModele::de($source) === CleDuModele::de(app(TenantContext::class)->magasin())
                ? Magasin::query()->whereKey($source->getKey())->where('motif_fermeture', MotifFermeture::Cession->value)->first()
                : null,
            $source instanceof Facture => Facture::query()->whereKey($source->getKey())->whereNotNull('numero')->whereNull('facture_rectifiee_id')->first(),
            $source instanceof LigneAvoir => LigneAvoir::query()->whereKey($source->getKey())->whereNotNull('ligne_facture_id')
                ->whereHas('avoir', fn ($avoir) => $avoir->whereNotNull('numero')->where('regularisation', false))->first(),
            $source instanceof FactureAchat => FactureAchat::query()->whereKey($source->getKey())->where('statut', StatutFactureAchat::Validee->value)->first(),
            $source instanceof InventaireOuverture => InventaireOuverture::query()->whereKey($source->getKey())->where('statut', StatutInventaireOuverture::Valide->value)->first(),
            $source instanceof AvoirFournisseur => AvoirFournisseur::query()->whereKey($source->getKey())->where('statut', StatutAvoirFournisseur::Emise->value)->first(),
            default => null,
        };

        return $document ?? throw new MouvementDeStockInjustifieException(MouvementDeStockInjustifieException::SANS_DOCUMENT);
    }

    /**
     * Ce que le document porte de ce produit, en articles : null s'il ne le
     * porte pas (zéro pour la facture d'achat que corrige un ajustement, le
     * produit en ayant été retiré).
     */
    private function plafond(TypeMouvementStock $type, Model $document, Produit $produit): ?int
    {
        if ($document instanceof LigneAvoir) {
            return (int) $document->produit_id === CleDuModele::de($produit) ? $document->quantite : null;
        }

        $lignes = match (true) {
            $document instanceof Facture => $document->lignes(),
            $document instanceof FactureAchat => $document->lignes(),
            $document instanceof InventaireOuverture => $document->lignes(),
            $document instanceof AvoirFournisseur => $document->lignes(),
            default => null,
        };
        $quantites = $lignes?->where('produit_id', $produit->getKey())->pluck('quantite');

        if ($quantites === null || ($quantites->isEmpty() && ! $type->estUnAjustement())) {
            return null;
        }

        return array_sum(ValeursLues::entiers($quantites->all()));
    }

    /**
     * La variation signée de tous les mouvements de ce document pour ce
     * produit, déjà inscrits.
     */
    private function variationDuDocument(Model $document, Produit $produit): int
    {
        return MouvementStock::query()
            ->where('produit_id', $produit->getKey())
            ->where('source_type', $document->getMorphClass())
            ->where('source_id', $document->getKey())
            ->get(['type', 'quantite'])
            ->sum(fn (MouvementStock $mouvement): int => $mouvement->type->variation($mouvement->quantite));
    }
}
