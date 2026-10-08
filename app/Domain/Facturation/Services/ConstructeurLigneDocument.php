<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Facturation\Enums\PrixReference;
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
    public function __construct(
        private readonly CalculateurLigne $calculateur,
        private readonly ResolveurPrixLigne $resolveurPrix,
    ) {}

    /**
     * Une ligne vend un produit du catalogue (un bien) ou un service du
     * magasin (un service, voir NatureLigne), jamais une ligne libre : sa
     * description et sa référence en sont la copie, elles ne se corrigent
     * que dans la fiche du produit ou du service.
     *
     * Le prix saisi (HT ou TTC, voir PrixReference) fait foi pour le calcul
     * de la ligne ; sans prix saisi, c'est le prix de vente TTC du produit
     * catalogue. Une ligne de service (voir Service) n'a pas de prix
     * catalogue : son prix est toujours saisi.
     *
     * @param  array{produit_id?: int, service_id?: int, quantite: int|string, prix_unitaire_ht?: float|string|null, prix_unitaire_ttc?: float|string|null, prix_reference?: string|PrixReference|null, taux_tva?: float|string, remise_pourcentage?: float|string}  $donnees
     * @return array<string, mixed>
     */
    public function construire(array $donnees, int $ordre): array
    {
        $produit = isset($donnees['produit_id'])
            ? Produit::query()->findOrFail($donnees['produit_id'])
            : null;

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

        $prix = $this->resolveurPrix->resoudre(
            $donnees,
            PrixReference::Ttc,
            $tauxTva,
            $produit === null ? null : (float) $produit->prix_vente_ht,
            $produit === null ? null : (float) $produit->prix_vente_ttc,
        );

        $remise = (float) ($donnees['remise_pourcentage'] ?? 0);

        $montants = $this->calculateur->calculer(
            $prix['prix_reference'],
            $prix['prix_unitaire_ht'],
            $prix['prix_unitaire_ttc'],
            $tauxTva,
            $quantite,
            $remise,
        );

        return [
            'produit_id' => $produit?->id,
            'service_id' => $service?->id,
            'nature' => $produit !== null ? NatureLigne::Bien : NatureLigne::Service,
            'description' => $produit?->nom ?? $service->nom,
            'reference' => $produit?->reference,
            'quantite' => $quantite,
            ...$prix,
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
