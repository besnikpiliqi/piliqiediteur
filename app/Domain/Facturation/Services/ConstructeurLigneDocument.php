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
 *
 * Une vente à un particulier se calcule ligne par ligne, article par
 * article ; une vente à un professionnel, depuis le HT de chaque ligne,
 * puis la TVA de chaque taux sur le total HT de ses lignes, répartie entre
 * elles (E1, voir repartirLaTvaParTaux()) : ses lignes ne se calculent
 * qu'ensemble. En franchise en base, toute vente se calcule article par
 * article, quel que soit le client (voir
 * ContexteDeVente::calculeDepuisLeHtDeLigne()). Hors de la vente en
 * France, elle se calcule de même, sans TVA (voir construire()).
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

        return $contexte->calculeDepuisLeHtDeLigne() ? $this->repartirLaTvaParTaux($lignes) : $lignes;
    }

    /**
     * Les lignes telles qu'elles sont saisies à l'écran, ou gardées dans un
     * panier de la caisse, calculées comme à l'enregistrement, par clé de
     * ligne : une ligne pas encore complète (aucun produit ni service
     * choisi, prix ou quantité à saisir, valeur hors limites) est ignorée,
     * plutôt que comptée pour un montant faux. Une ligne dont le produit a
     * été archivé depuis compte pour son montant : seul son enregistrement
     * la refuse (voir construireToutes()), et le total d'une vente
     * abandonnée la compte (voir PanierDeCaisse). La TVA d'une vente à un
     * professionnel se répartit entre les seules lignes complètes ; des
     * montants si absurdes que la TVA de leur total ne se calcule plus ne
     * donnent aucun aperçu.
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

        if (! $contexte->calculeDepuisLeHtDeLigne()) {
            return $lignes;
        }

        try {
            return $this->repartirLaTvaParTaux($lignes);
        } catch (InvalidArgumentException) {
            return [];
        }
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
     * la fiche. Une ligne de service (voir Service) prend le PU TTC saisi,
     * sinon son prix de référence TTC ; sans prix de référence, elle doit
     * en saisir un. Seule une personne autorisée met un autre prix que
     * celui de la fiche ou de la référence, ou vend un service qui n'en a
     * pas (voir DerogationsDePrix). Un PU TTC n'est jamais négatif : une
     * vente ne rembourse rien, c'est le rôle d'un avoir.
     *
     * Vendue à un particulier, ou en franchise en base, la ligne a ici
     * tous ses montants, article par article ; vendue à un professionnel
     * hors franchise, seulement son HT, quantité x PU TTC ÷ (1 + taux)
     * arrondi une fois -- sa TVA et son TTC attendent les autres lignes de
     * son taux (voir repartirLaTvaParTaux()). Son PU HT n'est alors qu'un
     * affichage : 1 000 x 0,83 € ne font pas les 833,33 € HT de la ligne.
     *
     * Le taux d'une ligne de produit est celui de sa fiche, quel que soit
     * celui envoyé (C15, C40) ; celui d'une ligne de service se saisit,
     * sinon celui de son prix de référence, sinon le taux du territoire du
     * magasin (B2). En franchise
     * en base, toute ligne est à 0 % : son PU HT est son PU TTC, et le
     * client, particulier ou professionnel, paie le même prix TTC sans TVA
     * (B10).
     *
     * Hors de la vente en France (K3, voir
     * ContexteDeVente::retireLaTvaDuPrix()), toute ligne est aussi à 0 %,
     * mais le client paie le prix sans la TVA française : le PU TTC d'un
     * produit comprend encore la TVA de sa fiche (taux_tva_du_prix), d'où
     * se déduit son PU HT, le prix payé par article -- 1 000 x 1,00 € TTC à
     * 20 % : 830,00 € pour un particulier, à 0,83 € l'article ; 833,33 €
     * depuis le HT de la ligne pour un professionnel. Un service à son prix
     * de référence se calcule de même, depuis ce prix TTC et son taux (voir
     * Service::vendAuPrixDeReferenceSansTva()) ; un autre prix saisi pour un
     * service y est déjà sans TVA.
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

        $auPrixDeReferenceSansTva = $service?->vendAuPrixDeReferenceSansTva($donnees['prix_unitaire_ttc'] ?? null, $contexte) ?? false;
        $prixSaisi = $auPrixDeReferenceSansTva ? null : ($donnees['prix_unitaire_ttc'] ?? null);

        $tauxTvaDuPrix = match (true) {
            $contexte->franchiseEnBase => 0.0,
            $produit !== null => (float) $produit->taux_tva,
            $auPrixDeReferenceSansTva => (float) $service->taux_tva,
            $contexte->retireLaTvaDuPrix() => 0.0,
            default => (float) (filled($donnees['taux_tva'] ?? null) ? $donnees['taux_tva'] : ($service->taux_tva ?? $contexte->tauxTvaParDefaut)),
        };
        $tauxTva = $contexte->exonere() ? 0.0 : $tauxTvaDuPrix;

        $quantite = QuantiteEntiere::depuisSaisie($donnees['quantite'] ?? null);

        $prixUnitaireTtc = $this->calculateur->arrondirAuCentime((float) (filled($prixSaisi)
            ? $prixSaisi
            : ($produit?->prix_vente_ttc ?? $service->prix_ttc ?? throw new InvalidArgumentException("Le prix du service « {$service->nom} » n'est pas fixé : demandez à un responsable."))));

        if ($prixUnitaireTtc < 0) {
            throw new InvalidArgumentException('Le PU TTC d\'une ligne ne peut pas être négatif.');
        }

        $prixUnitaireHt = $this->calculateur->prixUnitaireHtDepuisTtc($prixUnitaireTtc, $tauxTvaDuPrix);
        // Le PU TTC que paie le client : sans la TVA retirée du prix, c'est
        // son PU HT.
        $prixUnitaireTtcPaye = $contexte->retireLaTvaDuPrix() ? $prixUnitaireHt : $prixUnitaireTtc;
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
            'taux_tva_du_prix' => $tauxTvaDuPrix,
            'remise_pourcentage' => $remise,
            'ordre' => $ordre,
            ...($contexte->calculeDepuisLeHtDeLigne()
                ? ['montant_ht' => $this->calculateur->montantHtDeLigneProfessionnelle($prixUnitaireTtc, $tauxTvaDuPrix, $quantite, $remise)]
                : $this->calculateur->montantsDeVente($prixUnitaireHt, $prixUnitaireTtcPaye, $quantite, $remise)),
        ];
    }

    /**
     * Vente à un professionnel (E1) : la TVA de chaque taux se calcule une
     * seule fois sur le total HT de ses lignes, puis se répartit entre
     * elles, dans leur ordre (voir CalculateurLigne::repartirLaTva()) ; le
     * TTC de chaque ligne est son HT plus sa part de TVA. Le total TVA du
     * document est donc, taux par taux, la TVA de son total HT.
     *
     * @param  array<array-key, array<string, mixed>>  $lignes  les lignes construites, avec leur seul HT, dans leur ordre
     * @return array<array-key, array<string, mixed>>
     *
     * @throws InvalidArgumentException
     */
    private function repartirLaTvaParTaux(array $lignes): array
    {
        $clesParTaux = [];

        foreach ($lignes as $cle => $ligne) {
            $clesParTaux[number_format($ligne['taux_tva'], 2, '.', '')][] = $cle;
        }

        foreach ($clesParTaux as $tauxTva => $cles) {
            $parts = $this->calculateur->repartirLaTva(array_map(fn (int|string $cle): float => $lignes[$cle]['montant_ht'], $cles), (float) $tauxTva);

            foreach ($cles as $index => $cle) {
                $lignes[$cle]['montant_tva'] = $parts[$index];
                $lignes[$cle]['montant_ttc'] = $this->calculateur->sommer([$lignes[$cle]['montant_ht'], $parts[$index]]);
            }
        }

        return $lignes;
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
