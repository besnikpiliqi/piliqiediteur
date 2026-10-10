<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Facturation\Enums\NatureLigne;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;

/**
 * Construit le tableau d'attributs d'une ligne (devis ou facture) prêt
 * pour `$document->lignes()->create($ligne)`, en figeant un snapshot :
 * si un produit catalogue est fourni, ses valeurs ACTUELLES sont copiées
 * une fois pour toutes -- la ligne ne référence plus jamais le produit
 * pour ses montants après coup.
 *
 * Partagé entre les actions qui enregistrent un devis ou une facture et
 * les écrans qui en montrent les montants pendant la saisie (voir
 * apercu()) : l'écran ne peut jamais afficher un centime différent de ce
 * qui sera enregistré.
 */
class ConstructeurLigneDocument
{
    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * Les lignes d'un document, dans leur ordre.
     *
     * Un produit archivé ne se vend plus (voir ProduitResource) : la ligne
     * qui le porte est refusée avec un message, plutôt qu'une page
     * d'erreur. Un brouillon déjà composé avec lui s'émet toujours, sans
     * repasser par ici, et le sort du stock (voir LigneFacture::produit()).
     *
     * @param  list<array<string, mixed>>  $lignesDonnees
     * @return list<array<string, mixed>>
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws ModelNotFoundException
     */
    public function construireToutes(array $lignesDonnees, ContexteDeVente $contexte): array
    {
        if ($lignesDonnees === []) {
            throw new InvalidArgumentException('Un document doit contenir au moins une ligne.');
        }

        [$produits, $services] = $this->catalogue($lignesDonnees);
        $lignes = [];

        foreach (array_values($lignesDonnees) as $index => $donnees) {
            $produit = filled($donnees['produit_id'] ?? null) ? $produits->get((int) $donnees['produit_id']) : null;

            if ($produit?->trashed()) {
                throw new LogicException("Le produit « {$produit->nom} » est archivé : restaurez-le depuis le catalogue, ou retirez-le du document.");
            }

            $lignes[] = $this->construire($donnees, $index, $contexte, $produits, $services);
        }

        return $lignes;
    }

    /**
     * Les lignes telles qu'elles sont saisies à l'écran, ou gardées dans un
     * panier de la caisse, calculées comme à l'enregistrement, par clé de
     * ligne : une ligne pas encore complète (aucun produit ni service
     * choisi, prix ou quantité à saisir, valeur hors limites) est ignorée,
     * plutôt que comptée pour un montant faux. Une ligne dont le produit a
     * été archivé depuis compte pour son montant : seul son enregistrement
     * la refuse (voir construireToutes()), et le total d'une vente
     * abandonnée la compte (voir PanierDeCaisse).
     *
     * @param  array<array-key, mixed>  $lignesSaisies
     * @return array<array-key, array<string, mixed>>
     */
    public function apercu(array $lignesSaisies, ContexteDeVente $contexte): array
    {
        $lignesSaisies = array_filter($lignesSaisies, 'is_array');
        [$produits, $services] = $this->catalogue($lignesSaisies);
        $lignes = [];
        $ordre = 0;

        foreach ($lignesSaisies as $cle => $donnees) {
            try {
                $lignes[$cle] = $this->construire($donnees, $ordre++, $contexte, $produits, $services);
            } catch (InvalidArgumentException|ModelNotFoundException) {
                continue;
            }
        }

        return $lignes;
    }

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
     * Le taux d'une ligne de produit est celui de sa fiche, quel que soit
     * celui envoyé (C15, C40) ; celui d'une ligne de service se saisit
     * librement, sinon le taux du territoire du magasin (B2). En franchise
     * en base, toute ligne est à 0 % : son PU HT est son PU TTC, et le
     * client paie le même prix TTC sans TVA (B10).
     *
     * @param  array{produit_id?: int|string|null, service_id?: int|string|null, quantite: int|string, prix_unitaire_ttc?: float|string|null, taux_tva?: float|string|null, remise_pourcentage?: float|string|null}  $donnees
     * @param  Collection<int, Produit>  $produits  les produits des lignes, archivés compris (voir catalogue())
     * @param  Collection<int, Service>  $services  les services des lignes
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     * @throws ModelNotFoundException un produit ou un service qui n'est pas du magasin actif
     */
    private function construire(array $donnees, int $ordre, ContexteDeVente $contexte, Collection $produits, Collection $services): array
    {
        $produit = filled($donnees['produit_id'] ?? null)
            ? ($produits->get((int) $donnees['produit_id']) ?? throw (new ModelNotFoundException)->setModel(Produit::class, [$donnees['produit_id']]))
            : null;

        $service = filled($donnees['service_id'] ?? null)
            ? ($services->get((int) $donnees['service_id']) ?? throw (new ModelNotFoundException)->setModel(Service::class, [$donnees['service_id']]))
            : null;

        if ($produit !== null && $service !== null) {
            throw new InvalidArgumentException('Une ligne est un produit ou un service, jamais les deux.');
        }

        if ($produit === null && $service === null) {
            throw new InvalidArgumentException('Une ligne vend un produit ou un service du catalogue.');
        }

        $tauxTva = match (true) {
            $contexte->franchiseEnBase => 0.0,
            $produit !== null => (float) $produit->taux_tva,
            default => (float) (filled($donnees['taux_tva'] ?? null) ? $donnees['taux_tva'] : $contexte->tauxTvaParDefaut),
        };

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
     * Les produits (archivés compris : l'aperçu les compte, l'enregistrement
     * les refuse avec un message) et les services des lignes, archivés
     * compris eux aussi, lus en une requête chacun, limitée au
     * magasin actif par MagasinScope : un identifiant d'un autre magasin
     * n'y est pas, et sa ligne est introuvable.
     *
     * @param  array<array-key, array<string, mixed>>  $lignes
     * @return array{Collection<int, Produit>, Collection<int, Service>}
     */
    private function catalogue(array $lignes): array
    {
        $identifiants = fn (string $cle): array => collect($lignes)
            ->pluck($cle)
            ->filter(fn (mixed $identifiant): bool => is_int($identifiant) || (is_string($identifiant) && ctype_digit($identifiant)))
            ->map(fn (int|string $identifiant): int => (int) $identifiant)
            ->unique()
            ->values()
            ->all();

        $produits = $identifiants('produit_id');
        $services = $identifiants('service_id');

        return [
            $produits === [] ? collect() : Produit::withTrashed()->whereKey($produits)->get()->keyBy('id'),
            $services === [] ? collect() : Service::query()->whereKey($services)->get()->keyBy('id'),
        ];
    }
}
