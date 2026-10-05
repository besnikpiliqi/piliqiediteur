<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Catalogue\Models\Produit;
use InvalidArgumentException;

/**
 * Construit le tableau d'attributs d'une ligne (devis ou facture) prêt
 * pour `$document->lignes()->create($ligne)`, en figeant un snapshot :
 * si un produit catalogue est fourni, ses valeurs ACTUELLES sont copiées
 * une fois pour toutes -- la ligne ne référence plus jamais le produit
 * pour ses montants après coup.
 *
 * Partagé entre CreerDevisAction et CreerFactureAction pour éviter de
 * dupliquer cette logique.
 */
class ConstructeurLigneDocument
{
    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * Le PU TTC fait référence (voir CalculateurLigne::calculerDepuisTtc()) :
     * fourni tel quel, sinon déduit d'un PU HT fourni, sinon repris du
     * produit catalogue.
     *
     * @param  array{produit_id?: int, description?: string, reference?: string, quantite: float|string, prix_unitaire_ht?: float|string|null, prix_unitaire_ttc?: float|string|null, taux_tva?: float|string, remise_pourcentage?: float|string}  $donnees
     * @return array<string, mixed>
     */
    public function construire(array $donnees, int $ordre): array
    {
        $produit = isset($donnees['produit_id'])
            ? Produit::query()->findOrFail($donnees['produit_id'])
            : null;

        $description = $donnees['description'] ?? $produit?->nom
            ?? throw new InvalidArgumentException('Une ligne sans produit_id doit fournir une description.');

        $tauxTva = (float) ($donnees['taux_tva'] ?? $produit?->taux_tva
            ?? throw new InvalidArgumentException('Une ligne sans produit_id doit fournir taux_tva.'));

        $prixUnitaireTtc = $this->prixUnitaireTtc($donnees, $produit, $tauxTva);

        if (! isset($donnees['quantite'])) {
            throw new InvalidArgumentException('Chaque ligne doit fournir une quantite.');
        }

        $quantite = (float) $donnees['quantite'];
        $remise = (float) ($donnees['remise_pourcentage'] ?? 0);

        $montants = $this->calculateur->calculerDepuisTtc($prixUnitaireTtc, $quantite, $tauxTva, $remise);

        return [
            'produit_id' => $produit?->id,
            'description' => $description,
            'reference' => $donnees['reference'] ?? $produit?->reference,
            'quantite' => $quantite,
            'prix_unitaire_ht' => $this->calculateur->prixUnitaireHtDepuisTtc($prixUnitaireTtc, $tauxTva),
            'prix_unitaire_ttc' => $prixUnitaireTtc,
            'taux_tva' => $tauxTva,
            'remise_pourcentage' => $remise,
            'ordre' => $ordre,
            ...$montants,
        ];
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    private function prixUnitaireTtc(array $donnees, ?Produit $produit, float $tauxTva): float
    {
        if (filled($donnees['prix_unitaire_ttc'] ?? null)) {
            return $this->calculateur->arrondirAuCentime((float) $donnees['prix_unitaire_ttc']);
        }

        if (filled($donnees['prix_unitaire_ht'] ?? null)) {
            return $this->calculateur->prixUnitaireTtcDepuisHt((float) $donnees['prix_unitaire_ht'], $tauxTva);
        }

        if ($produit !== null) {
            return (float) $produit->prix_vente_ttc;
        }

        throw new InvalidArgumentException('Une ligne sans produit_id doit fournir prix_unitaire_ttc ou prix_unitaire_ht.');
    }

    /**
     * @param  list<array<string, mixed>>  $lignesDonnees
     * @return list<array<string, mixed>>
     */
    public function construireToutes(array $lignesDonnees): array
    {
        if ($lignesDonnees === []) {
            throw new InvalidArgumentException('Un document doit contenir au moins une ligne.');
        }

        $lignes = [];

        foreach (array_values($lignesDonnees) as $index => $donnees) {
            $lignes[] = $this->construire($donnees, $index);
        }

        return $lignes;
    }
}
