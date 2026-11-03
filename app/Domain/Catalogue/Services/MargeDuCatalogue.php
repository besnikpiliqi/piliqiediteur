<?php

namespace App\Domain\Catalogue\Services;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\SaisieDecimale;

/**
 * Ce que la fiche d'un produit dit de sa vente au magasin, tel que le
 * tableau des produits le montre (voir SelecteurDeProduits) : le taux
 * que prend une ligne qui le vend, celui de sa fiche, ou 0 % en franchise
 * en base (voir ConstructeurLigneDocument) ; son prix de vente HT, son
 * prix TTC ÷ (1 + ce taux), au centime, comme le détail de sa fiche (voir
 * ProduitResource::detailPrixDeVente()) ; son bénéfice unitaire HT sur
 * son coût moyen, arrondi une fois au centime, comme le bénéfice que
 * montre une facture d'achat (voir CalculateurLigne::beneficeUnitaire()) ;
 * et son taux de marque, ce bénéfice ÷ ce prix HT.
 *
 * Une information du catalogue, jamais le montant d'un document : une
 * vente se calcule ligne à ligne avec les autres lignes de son taux (voir
 * CalculateurLigne::chiffrerUneVente()).
 */
final readonly class MargeDuCatalogue
{
    /**
     * @param  int  $tauxTva  en centièmes de point
     * @param  int  $prixVenteHt  en centimes
     * @param  int  $beneficeUnitaireHt  en centimes
     * @param  int|null  $tauxDeMarque  en centièmes de point ; aucun sans prix de vente
     */
    public function __construct(
        public int $tauxTva,
        public int $prixVenteHt,
        public int $beneficeUnitaireHt,
        public ?int $tauxDeMarque,
    ) {}

    public static function de(Produit $produit, bool $franchiseEnBase): self
    {
        $calculateur = app(CalculateurLigne::class);
        $tauxTva = $franchiseEnBase ? 0 : $calculateur->enCentiemes($produit->taux_tva);
        $prixVenteHt = $calculateur->prixUnitaireHtDepuisTtc($calculateur->enCentiemes($produit->prix_vente_ttc), $tauxTva);
        $benefice = SaisieDecimale::enCentiemes($calculateur->beneficeUnitaire((float) $calculateur->enDecimal($prixVenteHt), (float) $produit->cmup_ht));

        return new self($tauxTva, $prixVenteHt, $benefice, $calculateur->tauxDeMarque($benefice, $prixVenteHt));
    }
}
