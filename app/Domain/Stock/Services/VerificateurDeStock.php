<?php

namespace App\Domain\Stock\Services;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\LigneFacture;
use App\Domain\Stock\Exceptions\ConfirmationVenteSansStockRequiseException;
use App\Domain\Stock\Exceptions\StockInsuffisantException;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Le contrôle du stock d'une vente (R1), que fait l'émission de toute
 * facture et de tout ticket, seul moment où le stock sort (voir
 * EmettreFactureAction) : la caisse, une facture, un devis converti y
 * passent tous.
 *
 * Une vente qui ferait passer sous zéro un produit qui suit son stock,
 * toutes ses lignes de ce produit comprises, est un manque (voir
 * ManqueDeStock). Sans l'autorisation de vendre sans stock dans ce
 * magasin, elle est refusée (voir StockInsuffisantException). Avec, elle
 * ne passe qu'après l'avertissement, confirmé pour un stock qui n'est pas
 * plus haut que le stock réel (voir ConfirmationVenteSansStock) ; sinon
 * l'avertissement se renouvelle (voir
 * ConfirmationVenteSansStockRequiseException). Un produit qui ne suit pas
 * son stock n'est jamais un manque, un produit archivé qui le suit en est
 * un comme les autres.
 */
class VerificateurDeStock
{
    /**
     * Verrouille, par identifiant croissant, les produits de la facture
     * (un produit qui se mettrait à suivre son stock entre-temps ne sort
     * donc pas sans contrôle), puis exige, s'il y a des manques, que le
     * compte, relu sous le verrou de sa ligne par l'émission (voir
     * LecteurDesDroits::exigerSousVerrou()), soit autorisé à vendre sans
     * stock dans le magasin actif et ait confirmé l'avertissement pour ces
     * manques. À appeler dans la transaction de l'émission, après les
     * verrous du registre, du compte et de la facture, avant la
     * numérotation : la sortie de stock (voir
     * DecrementerStockApresEmissionFacture) retrouve ces produits déjà
     * verrouillés, et deux ventes du même produit passent l'une après
     * l'autre, la seconde voyant le stock que la première a laissé.
     *
     * @return list<ManqueDeStock> les manques confirmés, que l'émission journalise et signale
     *
     * @throws StockInsuffisantException
     * @throws ConfirmationVenteSansStockRequiseException
     */
    public function verifier(User $compte, Facture $facture, ?ConfirmationVenteSansStock $confirmation): array
    {
        $quantites = $this->quantitesParProduit($facture);

        if ($quantites === []) {
            return [];
        }

        $manques = $this->manquesParmi(Produit::verrouillerPourLeStock(array_keys($quantites)), $quantites);

        if ($manques === []) {
            return [];
        }

        if (Gate::forUser($compte)->denies(PermissionRegistry::STOCK_VENDRE_SANS_STOCK)) {
            throw new StockInsuffisantException($manques);
        }

        foreach ($manques as $manque) {
            if ($confirmation === null || ! $confirmation->couvre($manque)) {
                throw new ConfirmationVenteSansStockRequiseException($manques);
            }
        }

        return $manques;
    }

    /**
     * La quantité vendue de chaque produit de la facture, toutes ses lignes
     * comprises, par identifiant de produit croissant.
     *
     * @return array<int, int>
     */
    private function quantitesParProduit(Facture $facture): array
    {
        $quantites = [];

        foreach ($facture->lignes()->whereNotNull('produit_id')->get() as $ligne) {
            /** @var LigneFacture $ligne */
            $quantites[(int) $ligne->produit_id] = ($quantites[(int) $ligne->produit_id] ?? 0) + $ligne->quantite;
        }

        ksort($quantites);

        return $quantites;
    }

    /**
     * @param  Collection<int, Produit>  $produits
     * @param  array<int, int>  $quantites
     * @return list<ManqueDeStock>
     */
    private function manquesParmi(Collection $produits, array $quantites): array
    {
        return $produits
            ->filter(fn (Produit $produit): bool => $produit->suivi_stock && $produit->stock_quantite - $quantites[$produit->getKey()] < 0)
            ->map(fn (Produit $produit): ManqueDeStock => new ManqueDeStock($produit->getKey(), $produit->nom, (string) $produit->reference, $produit->stock_quantite, $quantites[$produit->getKey()]))
            ->values()
            ->all();
    }
}
