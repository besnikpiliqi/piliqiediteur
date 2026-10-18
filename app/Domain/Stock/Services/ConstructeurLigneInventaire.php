<?php

namespace App\Domain\Stock\Services;

use App\Domain\Achats\Services\ConstructeurLigneAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\QuantiteEntiere;
use App\Domain\Facturation\Services\SaisieDecimale;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use LogicException;

/**
 * Enregistre les lignes d'un inventaire d'ouverture brouillon et sa
 * valeur, à la création comme à la modification (voir
 * CreerInventaireOuvertureAction et ModifierInventaireOuvertureAction).
 *
 * Chaque ligne fait entrer en stock un produit du magasin jamais reçu
 * (voir Produit::scopeJamaisRecus()), qui n'est ni archivé ni déjà sur une
 * autre ligne, ou un nouveau produit, dont la ligne garde seulement la
 * fiche jusqu'à la validation, qui le crée (voir
 * ValiderInventaireOuvertureAction) : référence (saisie ou générée à la
 * validation), nom, prix de vente TTC et taux de TVA obligatoires,
 * code-barres, taille, couleur, suivi du stock et seuil d'alerte comme sur
 * une facture d'achat (voir ConstructeurLigneAchat::ATTRIBUTS_DU_PRODUIT).
 * Sa référence n'est ni celle d'un produit du magasin, archivés compris,
 * ni celle d'un autre nouveau produit de l'inventaire, sans tenir compte
 * de la casse.
 *
 * Chaque ligne porte une quantité entière d'au moins un article et un coût
 * unitaire HT au centime, jamais négatif : sa valeur est quantité x coût,
 * exacte en centimes, et celle de l'inventaire la somme de ses lignes.
 */
class ConstructeurLigneInventaire
{
    /**
     * La plus grande quantité qu'une ligne enregistre : celle que la
     * colonne entière de PostgreSQL contient.
     */
    public const QUANTITE_MAXIMUM = 2_147_483_647;

    /**
     * La plus grande valeur, en centimes, qu'une ligne et l'inventaire
     * enregistrent : celle de leur colonne decimal(14, 2).
     */
    private const VALEUR_MAXIMUM = 99_999_999_999_999;

    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * Enregistre les lignes de l'inventaire, dans leur ordre, à la place de
     * celles qu'il avait, puis sa valeur.
     *
     * @param  list<array{produit_id?: int|string|null, produit?: array<string, mixed>, quantite: mixed, cout_unitaire_ht: mixed}>  $lignes
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function enregistrer(InventaireOuverture $inventaire, array $lignes): void
    {
        if ($lignes === []) {
            throw new InvalidArgumentException('Un inventaire d\'ouverture doit contenir au moins une ligne.');
        }

        $inventaire->lignes()->delete();

        $valeurDeLInventaire = 0;
        $produitsInventories = [];
        $referencesDesNouveauxProduits = [];

        foreach (array_values($lignes) as $ordre => $donnees) {
            $quantite = QuantiteEntiere::depuisSaisie($donnees['quantite'] ?? null);
            $cout = $this->coutUnitaire($donnees['cout_unitaire_ht'] ?? null);
            $valeur = $this->valeur($quantite, $cout);

            if (filled($donnees['produit_id'] ?? null)) {
                $produit = $this->produitJamaisRecu($donnees['produit_id']);

                if (in_array($produit->getKey(), $produitsInventories, true)) {
                    throw new LogicException("Le produit « {$produit->nom} » figure déjà sur une autre ligne de cet inventaire d'ouverture.");
                }

                $produitsInventories[] = $produit->getKey();
                $fiche = null;
            } else {
                $produit = $this->nouveauProduit($donnees['produit'] ?? []);

                if (filled($produit->reference)) {
                    $cle = Produit::normaliserReference($produit->reference);

                    if (in_array($cle, $referencesDesNouveauxProduits, true)) {
                        throw new InvalidArgumentException("Deux nouveaux produits de cet inventaire d'ouverture ont la même référence « {$produit->reference} ».");
                    }

                    $referencesDesNouveauxProduits[] = $cle;
                }

                $fiche = array_filter($produit->only(ConstructeurLigneAchat::ATTRIBUTS_DU_PRODUIT), fn (mixed $valeur): bool => filled($valeur));
            }

            $inventaire->lignes()->create([
                'produit_id' => $produit->exists ? $produit->getKey() : null,
                'nouveau_produit' => $fiche,
                'description' => $produit->nom,
                'reference' => $produit->reference,
                'quantite' => $quantite,
                'cout_unitaire_ht' => $this->calculateur->enDecimal($cout),
                'valeur_ht' => $this->calculateur->enDecimal($valeur),
                'ordre' => $ordre,
            ]);

            $valeurDeLInventaire += $valeur;
        }

        if ($valeurDeLInventaire > self::VALEUR_MAXIMUM) {
            throw new InvalidArgumentException('Cet inventaire d\'ouverture dépasse ce que le logiciel accepte : répartissez son stock sur plusieurs inventaires.');
        }

        $inventaire->forceFill(['valeur_ht' => $this->calculateur->enDecimal($valeurDeLInventaire)])->save();
    }

    /**
     * Le produit d'une ligne, jamais reçu (voir Produit::aDejaEteRecu()) :
     * le magasin actif seulement (voir MagasinScope), et pas archivé.
     * Revérifié à la validation, sous le verrou des produits (voir
     * ValiderInventaireOuvertureAction).
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     */
    public function produitJamaisRecu(int|string $produitId): Produit
    {
        $produit = filter_var($produitId, FILTER_VALIDATE_INT) === false
            ? null
            : Produit::withTrashed()->whereKey((int) $produitId)->first();

        if ($produit === null) {
            throw new InvalidArgumentException('Ce produit est introuvable dans le magasin.');
        }

        if ($produit->trashed()) {
            throw new LogicException("Le produit « {$produit->nom} » a été archivé : restaurez-le depuis le catalogue, ou retirez-le de l'inventaire d'ouverture.");
        }

        if ($produit->aDejaEteRecu()) {
            throw new LogicException("Le produit « {$produit->nom} » a déjà été reçu : seul un produit jamais reçu entre dans un inventaire d'ouverture.");
        }

        return $produit;
    }

    /**
     * La fiche d'un nouveau produit, pas encore enregistrée : nom, prix de
     * vente TTC et taux de TVA obligatoires, un prix jamais négatif, un
     * taux entre 0 et 100 %, une référence libre dans le magasin.
     *
     * @param  array<string, mixed>  $attributs
     *
     * @throws InvalidArgumentException
     */
    private function nouveauProduit(array $attributs): Produit
    {
        $attributs = array_filter(Arr::only($attributs, ConstructeurLigneAchat::ATTRIBUTS_DU_PRODUIT), fn (mixed $valeur): bool => filled($valeur));

        if (! isset($attributs['nom'])) {
            throw new InvalidArgumentException('Chaque nouveau produit d\'un inventaire d\'ouverture doit avoir un nom.');
        }

        if (! isset($attributs['prix_vente_ttc'])) {
            throw new InvalidArgumentException("Indiquez le prix de vente TTC du nouveau produit « {$attributs['nom']} ».");
        }

        if (SaisieDecimale::enCentiemes($attributs['prix_vente_ttc']) < 0) {
            throw new InvalidArgumentException('Le prix de vente d\'un produit ne peut pas être négatif.');
        }

        $taux = SaisieDecimale::enCentiemes($attributs['taux_tva'] ?? app(TenantContext::class)->magasin()->tauxTvaParDefaut());

        if ($taux < 0 || $taux > 10000) {
            throw new InvalidArgumentException('Le taux de TVA doit être compris entre 0 et 100 %.');
        }

        $produit = new Produit([...$attributs, 'taux_tva' => $this->calculateur->enDecimal($taux)]);
        $produit->verifierQueSaReferenceEstLibre();

        return $produit;
    }

    /**
     * Le coût unitaire HT saisi, en centimes, jamais négatif.
     *
     * @throws InvalidArgumentException
     */
    private function coutUnitaire(mixed $saisie): int
    {
        if (blank($saisie)) {
            throw new InvalidArgumentException('Indiquez le coût unitaire HT de chaque ligne de l\'inventaire d\'ouverture.');
        }

        $cout = SaisieDecimale::enCentiemes($saisie);

        if ($cout < 0) {
            throw new InvalidArgumentException('Le coût unitaire d\'un produit ne peut pas être négatif.');
        }

        return $cout;
    }

    /**
     * Quantité x coût unitaire, en centimes, dans les limites des colonnes.
     *
     * @throws InvalidArgumentException
     */
    private function valeur(int $quantite, int $cout): int
    {
        $valeur = $quantite * $cout;

        if ($quantite > self::QUANTITE_MAXIMUM || ! is_int($valeur) || $valeur > self::VALEUR_MAXIMUM) {
            throw new InvalidArgumentException('Cette ligne dépasse ce que le logiciel accepte : réduisez sa quantité ou son coût.');
        }

        return $valeur;
    }
}
