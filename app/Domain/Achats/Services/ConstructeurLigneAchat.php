<?php

namespace App\Domain\Achats\Services;

use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\QuantiteEntiere;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use LogicException;

/**
 * Enregistre les lignes d'une facture d'achat et ses totaux, à la
 * création comme à la modification (voir CreerFactureAchatAction et
 * ModifierFactureAchatAction).
 *
 * Chaque ligne reçoit un produit du catalogue, jamais une ligne libre : la
 * traçabilité de l'avoir fournisseur (voir LigneAchat) n'a de sens que
 * pour un produit suivi. C'est un produit existant (produit_id), dont la
 * fiche peut être corrigée au passage, ou un nouveau produit créé avec la
 * facture et rattaché à son fournisseur -- dans la même transaction que la
 * facture : une saisie abandonnée ne laisse aucun produit derrière elle.
 * Un produit archivé ne se reçoit plus : la ligne qui le garde est
 * refusée, avec un message, tant qu'il n'est pas restauré.
 *
 * Le prix d'achat de la ligne et le prix de vente du produit sont deux
 * prix distincts : le prix de vente ne change jamais le prix d'achat, et
 * seul le barème du magasin, s'il l'a activé, donne son prix de vente à un
 * nouveau produit reçu sans prix (voir BaremePrixDeVente).
 *
 * Créer ou corriger un produit depuis une ligne suit les droits de la page
 * Produits (voir ProduitPolicy), vérifiés ici et non seulement dans le
 * formulaire, dont l'état se forge. Sans utilisateur connecté (facture
 * récupérée automatiquement chez le fournisseur), il n'y a aucun droit à
 * vérifier.
 */
class ConstructeurLigneAchat
{
    /**
     * Ce qu'une ligne peut dire de son produit, sous sa clé "produit".
     */
    public const ATTRIBUTS_DU_PRODUIT = [
        'reference',
        'code_barres',
        'nom',
        'taille',
        'couleur',
        'prix_vente_ttc',
        'taux_tva',
        'suivi_stock',
        'seuil_alerte',
    ];

    /**
     * Ce qu'une ligne peut effacer de la fiche de son produit ; un autre
     * attribut laissé vide garde sa valeur (ou prend sa valeur par défaut
     * pour un nouveau produit).
     */
    private const ATTRIBUTS_EFFACABLES = ['code_barres', 'taille', 'couleur'];

    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * @param  list<array{produit_id?: int|string|null, produit?: array<string, mixed>, quantite: int|string, prix_unitaire_ht?: float|string|null, taux_tva?: float|string|null, remise_pourcentage?: float|string|null}>  $lignes
     * @return list<LigneAchat>
     */
    public function enregistrer(FactureAchat $factureAchat, array $lignes): array
    {
        if ($lignes === []) {
            throw new InvalidArgumentException('Une facture d\'achat doit contenir au moins une ligne.');
        }

        $lignesCreees = [];

        foreach (array_values($lignes) as $ordre => $donnees) {
            $lignesCreees[] = $this->enregistrerUneLigne($factureAchat, $donnees, $ordre);
        }

        $factureAchat->forceFill($this->calculateur->totaliser(array_map(
            fn (LigneAchat $ligne): array => $ligne->only(['montant_ht', 'montant_tva', 'montant_ttc']),
            $lignesCreees,
        )))->save();

        return $lignesCreees;
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    private function enregistrerUneLigne(FactureAchat $factureAchat, array $donnees, int $ordre): LigneAchat
    {
        $produit = $this->produitDeLaLigne($factureAchat, $donnees);
        $quantite = QuantiteEntiere::depuisSaisie($donnees['quantite'] ?? null);
        $tauxTva = (float) ($donnees['taux_tva'] ?? $produit->taux_tva);
        $remise = (float) ($donnees['remise_pourcentage'] ?? 0);
        // Un achat part toujours du PU HT du fournisseur (voir
        // CalculateurLigne) : celui saisi, sinon le dernier prix d'achat du
        // produit.
        $prixUnitaireHt = $this->calculateur->arrondirAuCentime((float) (filled($donnees['prix_unitaire_ht'] ?? null)
            ? $donnees['prix_unitaire_ht']
            : (($produit->exists ? $produit->dernierPrixDAchatHt() : null) ?? throw new InvalidArgumentException('Une ligne d\'achat doit fournir prix_unitaire_ht.'))));

        if (! $produit->exists) {
            // Son coût réel (remise et escompte déduits) le remplacera à la
            // validation de la facture, voir
            // StockLedgerService::receptionner().
            $produit->cmup_ht = $prixUnitaireHt;

            $bareme = $factureAchat->magasin->baremePrixDeVenteActif();

            // Sans prix de vente donné, celui du barème du magasin (voir
            // BaremePrixDeVente), s'il en prévoit un à ce prix d'achat.
            $prixDuBareme = $produit->prix_vente_ttc === null
                ? $bareme?->prixDeVente($this->calculateur->coutUnitaireNet($prixUnitaireHt, $remise), (float) $produit->taux_tva)
                : null;

            if ($prixDuBareme !== null) {
                $produit->prix_vente_ttc = $prixDuBareme['prix_vente_ttc'];
            }
        }

        if (! $produit->exists || $produit->isDirty()) {
            $this->verifierLeDroitDEnregistrer($produit);
            $produit->save();
        }

        return $factureAchat->lignes()->create([
            'produit_id' => $produit->id,
            'description' => $produit->nom,
            'reference' => $produit->reference,
            'quantite' => $quantite,
            'prix_unitaire_ht' => $prixUnitaireHt,
            'taux_tva' => $tauxTva,
            'remise_pourcentage' => $remise,
            'ordre' => $ordre,
            ...$this->calculateur->montantsDAchat($prixUnitaireHt, $tauxTva, $quantite, $remise),
        ]);
    }

    private function verifierLeDroitDEnregistrer(Produit $produit): void
    {
        $utilisateur = auth()->user();

        if ($utilisateur === null) {
            return;
        }

        if (! $produit->exists && $utilisateur->cannot('create', Produit::class)) {
            throw new LogicException("Vous ne pouvez pas créer de produit : « {$produit->nom} » doit d'abord être ajouté au catalogue.");
        }

        if ($produit->exists && $utilisateur->cannot('update', $produit)) {
            throw new LogicException("Vous ne pouvez pas modifier la fiche du produit « {$produit->getOriginal('nom')} » : seule la ligne se modifie ici.");
        }
    }

    /**
     * Le produit existant, avec les corrections apportées depuis la ligne,
     * ou le nouveau produit -- pas encore enregistré dans les deux cas.
     *
     * @param  array<string, mixed>  $donnees
     */
    private function produitDeLaLigne(FactureAchat $factureAchat, array $donnees): Produit
    {
        $attributs = array_filter(
            Arr::only($donnees['produit'] ?? [], self::ATTRIBUTS_DU_PRODUIT),
            fn (mixed $valeur, string $attribut): bool => filled($valeur) || in_array($attribut, self::ATTRIBUTS_EFFACABLES, true),
            ARRAY_FILTER_USE_BOTH,
        );

        if (filled($donnees['produit_id'] ?? null)) {
            $produit = Produit::withTrashed()->findOrFail($donnees['produit_id']);

            if ($produit->trashed()) {
                throw new LogicException("Le produit « {$produit->nom} » a été archivé : restaurez-le depuis le catalogue, ou retirez-le de la facture.");
            }

            $produit->fill($attributs);

            // Seule une autre facture récupérée automatiquement, donc la
            // source elle-même, peut encore corriger ce que le fournisseur
            // dit du produit.
            if ($factureAchat->source !== SourceFactureAchat::Api
                && $produit->isDirty(Produit::ATTRIBUTS_DU_FOURNISSEUR)
                && $produit->vientDUneFactureAchatApi()) {
                throw new LogicException("Le produit « {$produit->getOriginal('nom')} » vient d'une facture d'achat récupérée automatiquement : ses informations fournisseur ne se modifient pas.");
            }

            return $produit;
        }

        if (! isset($attributs['nom'])) {
            throw new InvalidArgumentException('Chaque ligne d\'une facture d\'achat doit référencer un produit du catalogue, existant ou nouveau.');
        }

        return new Produit([
            'taux_tva' => $donnees['taux_tva'] ?? 20,
            ...$attributs,
            'fournisseur_id' => $factureAchat->fournisseur_id,
        ]);
    }
}
