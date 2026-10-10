<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Facturation\Enums\NatureLigne;
use InvalidArgumentException;
use LogicException;

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
     * Une ligne vend un produit du catalogue (un bien) ou un service du
     * magasin (un service, voir NatureLigne), jamais une ligne libre : sa
     * description et sa référence en sont la copie, elles ne se corrigent
     * que dans la fiche du produit ou du service.
     *
     * Une vente part toujours d'un prix TTC (voir CalculateurLigne) : le PU
     * TTC saisi, sinon le prix de vente TTC enregistré sur la fiche du
     * produit ; son PU HT en est la conversion au centime, la même que sur
     * la fiche. Une ligne de service (voir Service) n'a pas de prix
     * catalogue : son PU TTC est toujours saisi. Un PU TTC n'est jamais
     * négatif : une vente ne rembourse rien, c'est le rôle d'un avoir.
     *
     * Un produit archivé ne se vend plus (voir ProduitResource) : la ligne
     * qui le porte est refusée avec un message, plutôt qu'une page
     * d'erreur. Un brouillon déjà composé avec lui s'émet toujours, sans
     * repasser par ici, et le sort du stock (voir LigneFacture::produit()).
     *
     * @param  array{produit_id?: int, service_id?: int, quantite: int|string, prix_unitaire_ttc?: float|string|null, taux_tva?: float|string, remise_pourcentage?: float|string}  $donnees
     * @return array<string, mixed>
     *
     * @throws LogicException
     */
    public function construire(array $donnees, int $ordre): array
    {
        $produit = isset($donnees['produit_id'])
            ? Produit::withTrashed()->findOrFail($donnees['produit_id'])
            : null;

        if ($produit?->trashed()) {
            throw new LogicException("Le produit « {$produit->nom} » est archivé : restaurez-le depuis le catalogue, ou retirez-le du document.");
        }

        $service = isset($donnees['service_id'])
            ? Service::query()->findOrFail($donnees['service_id'])
            : null;

        if ($produit !== null && $service !== null) {
            throw new InvalidArgumentException('Une ligne est un produit ou un service, jamais les deux.');
        }

        if ($produit === null && $service === null) {
            throw new InvalidArgumentException('Une ligne vend un produit ou un service du catalogue.');
        }

        $tauxTva = (float) ($donnees['taux_tva'] ?? $produit?->taux_tva
            ?? throw new InvalidArgumentException('Une ligne de service doit fournir taux_tva.'));

        $quantite = QuantiteEntiere::depuisSaisie($donnees['quantite'] ?? null);

        $prixUnitaireTtc = $this->calculateur->arrondirAuCentime((float) (filled($donnees['prix_unitaire_ttc'] ?? null)
            ? $donnees['prix_unitaire_ttc']
            : ($produit?->prix_vente_ttc ?? throw new InvalidArgumentException('Une ligne de service doit fournir prix_unitaire_ttc.'))));

        if ($prixUnitaireTtc < 0) {
            throw new InvalidArgumentException('Le PU TTC d\'une ligne ne peut pas être négatif.');
        }

        $prixUnitaireHt = $this->calculateur->prixUnitaireHtDepuisTtc($prixUnitaireTtc, $tauxTva);
        $remise = (float) ($donnees['remise_pourcentage'] ?? 0);

        return [
            'produit_id' => $produit?->id,
            'service_id' => $service?->id,
            'nature' => $produit !== null ? NatureLigne::Bien : NatureLigne::Service,
            'description' => $produit?->nom ?? $service->nom,
            'reference' => $produit?->reference,
            'quantite' => $quantite,
            'prix_unitaire_ht' => $prixUnitaireHt,
            'prix_unitaire_ttc' => $prixUnitaireTtc,
            'taux_tva' => $tauxTva,
            'remise_pourcentage' => $remise,
            'ordre' => $ordre,
            ...$this->calculateur->montantsDeVente($prixUnitaireHt, $prixUnitaireTtc, $quantite, $remise),
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
