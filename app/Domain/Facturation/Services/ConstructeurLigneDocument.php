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
 * Construit les lignes d'un devis ou d'une facture, chacune prête pour
 * `$document->lignes()->create($ligne)`, en figeant un snapshot : si un
 * produit catalogue est fourni, ses valeurs ACTUELLES sont copiées une
 * fois pour toutes -- la ligne ne référence plus jamais le produit pour
 * ses montants après coup.
 *
 * Partagé entre les actions qui enregistrent un devis ou une facture et
 * les écrans qui en montrent les montants pendant la saisie (voir
 * apercu()) : l'écran ne peut jamais afficher un centime différent de ce
 * qui sera enregistré.
 *
 * Toute vente, à un particulier comme à un professionnel, se calcule en
 * une fois, depuis les prix TTC de ses lignes (voir
 * CalculateurLigne::chiffrerUneVente()) : le HT de chaque taux, arrondi une
 * fois, se répartit entre ses lignes, qui ne se calculent donc
 * qu'ensemble (voir chiffrer()). Un même panier a les mêmes montants pour
 * tout client.
 */
class ConstructeurLigneDocument
{
    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * Les lignes d'un document, dans leur ordre, calculées ensemble.
     *
     * Un produit archivé ne se vend plus (voir ProduitResource) : la ligne
     * qui le porte est refusée avec un message, plutôt qu'une page
     * d'erreur. Un brouillon déjà composé avec lui s'émet toujours, sans
     * repasser par ici, et le sort du stock (voir LigneFacture::produit()).
     *
     * @param  list<array<string, mixed>>  $lignesDonnees
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     * @throws ModelNotFoundException
     */
    public function construireToutes(array $lignesDonnees, ContexteDeVente $contexte): DocumentChiffre
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

        return $this->chiffrer($lignes);
    }

    /**
     * Les lignes telles qu'elles sont saisies à l'écran, ou gardées dans un
     * panier de la caisse, calculées comme à l'enregistrement, par clé de
     * ligne : une ligne pas encore complète (aucun produit ni service
     * choisi, prix ou quantité à saisir, valeur hors limites) ou dont les
     * montants ne se calculent pas, même seule (une quantité absurde), est
     * ignorée, plutôt que comptée pour un montant faux ou faisant tomber
     * tout le document à zéro, et les autres lignes se calculent ensemble,
     * sans elle. Une ligne dont le produit a été archivé depuis compte
     * pour son montant : seul son enregistrement la refuse (voir
     * construireToutes()), et le total d'une vente abandonnée la compte
     * (voir PanierDeCaisse). Seules des lignes valables dont le total
     * dépasse ce que le calcul accepte ne donnent aucun aperçu.
     *
     * @param  array<array-key, mixed>  $lignesSaisies
     */
    public function apercu(array $lignesSaisies, ContexteDeVente $contexte): DocumentChiffre
    {
        $lignesSaisies = array_filter($lignesSaisies, 'is_array');
        [$produits, $services] = $this->catalogue($lignesSaisies);
        $lignes = [];
        $ordre = 0;

        foreach ($lignesSaisies as $cle => $donnees) {
            try {
                $ligne = $this->construire($donnees, $ordre++, $contexte, $produits, $services);
                $this->chiffrer([$ligne]);
            } catch (InvalidArgumentException|ModelNotFoundException) {
                continue;
            }

            $lignes[$cle] = $ligne;
        }

        try {
            return $this->chiffrer($lignes);
        } catch (InvalidArgumentException) {
            return DocumentChiffre::vide();
        }
    }

    /**
     * Les montants des lignes construites, calculés ensemble depuis leurs
     * prix TTC (voir CalculateurLigne::chiffrerUneVente()), dans leur ordre.
     *
     * @param  array<array-key, array<string, mixed>>  $lignes  voir construire()
     *
     * @throws InvalidArgumentException
     */
    private function chiffrer(array $lignes): DocumentChiffre
    {
        return DocumentChiffre::depuisLeCalcul($lignes, $this->calculateur->chiffrerUneVente(array_values(array_map(
            fn (array $ligne): array => [
                'prix_unitaire_ttc' => $this->calculateur->enCentiemes($ligne['prix_unitaire_ttc']),
                'quantite' => $ligne['quantite'],
                'remise_pourcentage' => $this->calculateur->enCentiemes($ligne['remise_pourcentage']),
                'taux_tva_du_prix' => $this->calculateur->enCentiemes($ligne['taux_tva_du_prix']),
                'taux_tva' => $this->calculateur->enCentiemes($ligne['taux_tva']),
            ],
            $lignes,
        ))), $this->calculateur);
    }

    /**
     * Une ligne vend un produit du catalogue (un bien) ou un service du
     * magasin (un service, voir NatureLigne), jamais une ligne libre : sa
     * description et sa référence en sont la copie, elles ne se corrigent
     * que dans la fiche du produit ou du service. Ses prix, taux et remise
     * s'écrivent comme leurs colonnes (« 25.00 », « 20.00 ») ; ses
     * montants se calculent avec les autres lignes du document (voir
     * chiffrer()).
     *
     * Une vente part toujours d'un prix TTC (voir CalculateurLigne) : le PU
     * TTC saisi, sinon le prix de vente TTC enregistré sur la fiche du
     * produit ; son PU HT en est la conversion au millionième d'euro (voir
     * CalculateurLigne::prixUnitaireHtExact()), que le document imprime et
     * qui ne compte dans aucun montant. Une ligne de
     * service (voir Service) prend le PU TTC saisi, sinon son prix de
     * référence TTC ; sans prix de référence, elle doit en saisir un. Seule
     * une personne autorisée met un autre prix que celui de la fiche ou de
     * la référence, ou vend un service qui n'en a pas (voir
     * DerogationsDePrix). Un PU TTC n'est jamais négatif : une vente ne
     * rembourse rien, c'est le rôle d'un avoir.
     *
     * Le taux d'une ligne de produit est celui de sa fiche, quel que soit
     * celui envoyé (C15, C40) ; celui d'une ligne de service se saisit,
     * sinon celui de son prix de référence, sinon le taux du territoire du
     * magasin (B2). En franchise en base, toute ligne est à 0 % : son PU HT
     * est son PU TTC, et le client, particulier ou professionnel, paie le
     * même prix TTC sans TVA (B10).
     *
     * Hors de la vente en France (K3, voir
     * ContexteDeVente::retireLaTvaDuPrix()), toute ligne est aussi à 0 %,
     * mais le client paie le prix sans la TVA française : le PU TTC d'un
     * produit comprend encore la TVA de sa fiche (taux_tva_du_prix), que le
     * calcul retire, taux par taux -- 2 x 40,00 € TTC à 20 % font 66,67 €.
     * Un service à son prix de référence se calcule de même, depuis ce prix
     * TTC et son taux (voir Service::vendAuPrixDeReferenceSansTva()) ; un
     * autre prix saisi pour un service y est déjà sans TVA.
     *
     * @param  array{produit_id?: int|string|null, service_id?: int|string|null, quantite: int|string, prix_unitaire_ttc?: float|int|string|null, taux_tva?: float|int|string|null, remise_pourcentage?: float|int|string|null}  $donnees
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

        $auPrixDeReferenceSansTva = $service?->vendAuPrixDeReferenceSansTva($donnees['prix_unitaire_ttc'] ?? null, $contexte) ?? false;
        $prixSaisi = $auPrixDeReferenceSansTva ? null : ($donnees['prix_unitaire_ttc'] ?? null);

        $tauxTvaDuPrix = match (true) {
            $contexte->franchiseEnBase => 0,
            $produit !== null => $this->calculateur->enCentiemes($produit->taux_tva),
            $auPrixDeReferenceSansTva => $this->calculateur->enCentiemes($service->taux_tva),
            $contexte->retireLaTvaDuPrix() => 0,
            default => SaisieDecimale::enCentiemes(filled($donnees['taux_tva'] ?? null) ? $donnees['taux_tva'] : ($service->taux_tva ?? $contexte->tauxTvaParDefaut)),
        };
        $tauxTva = $contexte->exonere() ? 0 : $tauxTvaDuPrix;

        $quantite = QuantiteEntiere::depuisSaisie($donnees['quantite'] ?? null);

        $prixUnitaireTtc = filled($prixSaisi)
            ? SaisieDecimale::enCentiemes($prixSaisi)
            : $this->calculateur->enCentiemes($produit?->prix_vente_ttc ?? $service->prix_ttc ?? throw new InvalidArgumentException("Le prix du service « {$service->nom} » n'est pas fixé : demandez à un responsable."));

        if ($prixUnitaireTtc < 0) {
            throw new InvalidArgumentException('Le PU TTC d\'une ligne ne peut pas être négatif.');
        }

        $remise = filled($donnees['remise_pourcentage'] ?? null) ? SaisieDecimale::enCentiemes($donnees['remise_pourcentage']) : 0;

        if ($remise < 0 || $remise > 10000) {
            throw new InvalidArgumentException('La remise doit être comprise entre 0 et 100 %.');
        }

        return [
            'produit_id' => $produit?->id,
            'service_id' => $service?->id,
            'nature' => $produit !== null ? NatureLigne::Bien : NatureLigne::Service,
            'description' => $produit?->nom ?? $service->nom,
            'reference' => $produit?->reference,
            'quantite' => $quantite,
            'prix_unitaire_ht' => $this->calculateur->enDecimalAuMillionieme($this->calculateur->prixUnitaireHtExact($prixUnitaireTtc, $tauxTvaDuPrix)),
            'prix_unitaire_ttc' => $this->calculateur->enDecimal($prixUnitaireTtc),
            'taux_tva' => $this->calculateur->enDecimal($tauxTva),
            'taux_tva_du_prix' => $this->calculateur->enDecimal($tauxTvaDuPrix),
            'remise_pourcentage' => $this->calculateur->enDecimal($remise),
            'ordre' => $ordre,
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
