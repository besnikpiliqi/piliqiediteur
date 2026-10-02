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
     * @param  array{produit_id?: int, description?: string, reference?: string, quantite: float|string, prix_unitaire_ht?: float|string, taux_tva?: float|string, remise_pourcentage?: float|string}  $donnees
     * @return array<string, mixed>
     */
    public function construire(array $donnees, int $ordre): array
    {
        $produit = isset($donnees['produit_id'])
            ? Produit::query()->findOrFail($donnees['produit_id'])
            : null;

        $description = $donnees['description'] ?? $produit?->nom
            ?? throw new InvalidArgumentException('Une ligne sans produit_id doit fournir une description.');

        $prixUnitaireHt = (float) ($donnees['prix_unitaire_ht'] ?? $produit?->prix_vente_ht
            ?? throw new InvalidArgumentException('Une ligne sans produit_id doit fournir prix_unitaire_ht.'));

        $tauxTva = (float) ($donnees['taux_tva'] ?? $produit?->taux_tva
            ?? throw new InvalidArgumentException('Une ligne sans produit_id doit fournir taux_tva.'));

        if (! isset($donnees['quantite'])) {
            throw new InvalidArgumentException('Chaque ligne doit fournir une quantite.');
        }

        $quantite = (float) $donnees['quantite'];
        $remise = (float) ($donnees['remise_pourcentage'] ?? 0);

        $montants = $this->calculateur->calculer($prixUnitaireHt, $quantite, $tauxTva, $remise);

        return [
            'produit_id' => $produit?->id,
            'description' => $description,
            'reference' => $donnees['reference'] ?? $produit?->reference,
            'quantite' => $quantite,
            'prix_unitaire_ht' => $prixUnitaireHt,
            'taux_tva' => $tauxTva,
            'remise_pourcentage' => $remise,
            'ordre' => $ordre,
            ...$montants,
        ];
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
