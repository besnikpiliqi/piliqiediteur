<?php

namespace App\Domain\Achats\Services;

use App\Domain\Achats\Enums\SourceFactureAchat;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Achats\Models\LigneAchat;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\QuantiteEntiere;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
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
 * fiche peut être corrigée au passage, tout de suite ; ou un nouveau
 * produit, dont la ligne d'un brouillon garde seulement la fiche
 * (LigneAchat::$nouveau_produit) : il n'est créé qu'à la validation de la
 * facture, rattaché à son fournisseur (voir creerLesNouveauxProduits()).
 * Un brouillon supprimé ne laisse ainsi aucun produit derrière lui, et ne
 * consomme aucune référence automatique. Seule la correction d'une
 * facture déjà validée, dont la marchandise est déjà reçue, crée son
 * nouveau produit tout de suite. Un produit archivé ne se reçoit plus : la
 * ligne qui le garde est refusée, avec un message, tant qu'il n'est pas
 * restauré.
 *
 * Le prix d'achat de la ligne et le prix de vente du produit sont deux
 * prix distincts : le prix de vente ne change jamais le prix d'achat, et
 * seul le barème du magasin, s'il l'a activé, donne son prix de vente à un
 * nouveau produit reçu sans prix (voir BaremePrixDeVente).
 *
 * Créer ou corriger un produit depuis une ligne suit les droits de la page
 * Produits (voir ProduitPolicy), vérifiés ici, à l'enregistrement des
 * lignes, et non seulement dans le formulaire, dont l'état se forge : la
 * fiche d'un produit existant se corrige avec le droit de la modifier, et
 * celle d'un nouveau produit se saisit ou se change avec le droit d'en
 * créer. La validation crée ensuite le produit que cette saisie a
 * autorisé, sans redemander ce droit (voir verifierLesNouveauxProduits()).
 * Sans utilisateur connecté (facture récupérée automatiquement chez le
 * fournisseur), il n'y a aucun droit à vérifier.
 *
 * Corriger le taux de TVA d'un produit existant s'inscrit au journal des
 * événements du magasin (voir JournaliseSesParametresDeConformite) : l'action
 * qui enregistre les lignes verrouille d'abord le registre fiscal, en tête
 * de sa transaction (voir verrouillerLeRegistreSiUnTauxChange()).
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

    public function __construct(
        private readonly CalculateurLigne $calculateur,
        private readonly RegistreFiscal $registre,
    ) {}

    /**
     * Verrouille le registre fiscal du magasin actif quand une ligne change
     * le taux de TVA d'un produit existant, paramètre ayant un impact sur la
     * conformité dont le changement s'inscrit au journal des événements : le
     * registre est le premier verrou de la transaction qui l'inscrit (voir
     * RegistreFiscal::sousVerrou()). L'action qui enregistre les lignes
     * l'appelle donc en tête de sa transaction, avant de verrouiller la
     * facture d'achat ou d'écrire un produit : sinon une vente, qui
     * verrouille le registre puis ses produits, et l'achat, qui tiendrait
     * un produit déjà corrigé en attendant le registre, s'attendraient
     * l'un l'autre. Une facture d'achat qui ne change aucun taux ne touche
     * pas au registre.
     *
     * @param  list<array<string, mixed>>  $lignes  voir enregistrer()
     */
    public function verrouillerLeRegistreSiUnTauxChange(array $lignes): void
    {
        foreach ($lignes as $donnees) {
            $produit = filled($donnees['produit_id'] ?? null) ? Produit::withTrashed()->find($donnees['produit_id']) : null;

            if ($produit?->fill(Arr::only(self::attributsDuProduit($donnees), Produit::parametresDeConformite()))->isDirty()) {
                $this->registre->verrouiller(app(TenantContext::class)->magasin());

                return;
            }
        }
    }

    /**
     * Enregistre les lignes de la facture d'achat, à la place de celles
     * qu'elle avait, puis ses totaux. La fiche de chaque nouveau produit
     * est vérifiée une fois toutes les lignes enregistrées (voir
     * verifierLesNouveauxProduits()). Une facture déjà validée crée tout
     * de suite ses nouveaux produits (voir creerLesNouveauxProduits()).
     *
     * Le droit de créer ou de corriger un produit se demande à son auteur,
     * relu par l'action qui l'appelle (voir verifierLeDroitDEnregistrer()).
     *
     * @param  list<array{produit_id?: int|string|null, produit?: array<string, mixed>, quantite: int|string, prix_unitaire_ht?: float|string|null, taux_tva?: float|string|null, remise_pourcentage?: float|string|null}>  $lignes
     */
    public function enregistrer(User $auteur, FactureAchat $factureAchat, array $lignes): void
    {
        if ($lignes === []) {
            throw new InvalidArgumentException('Une facture d\'achat doit contenir au moins une ligne.');
        }

        $fichesDejaSaisies = $factureAchat->lignes()
            ->whereNull('produit_id')
            ->get()
            ->map(fn (LigneAchat $ligne): array => self::ficheComparable($ligne->nouveau_produit))
            ->all();
        $factureAchat->lignes()->delete();

        $lignesEnregistrees = [];

        foreach (array_values($lignes) as $ordre => $donnees) {
            $lignesEnregistrees[] = $this->enregistrerUneLigne($auteur, $factureAchat, $donnees, $ordre);
        }

        $this->verifierLesNouveauxProduits($auteur, $lignesEnregistrees, $fichesDejaSaisies);

        $factureAchat->forceFill($this->calculateur->totaliser(array_map(
            fn (LigneAchat $ligne): array => $ligne->only(['montant_ht', 'montant_tva', 'montant_ttc']),
            $lignesEnregistrees,
        )))->save();

        // Sa marchandise est déjà reçue : le nouveau produit d'une
        // correction naît tout de suite, pour entrer en stock avec elle
        // (voir ModifierFactureAchatAction).
        if ($factureAchat->statut === StatutFactureAchat::Validee) {
            $this->creerLesNouveauxProduits($factureAchat);
        }
    }

    /**
     * Crée le produit de chaque ligne qui garde la fiche d'un nouveau
     * produit, à la validation de la facture d'achat (voir
     * ValiderFactureAchatAction) ou à la correction d'une facture déjà
     * validée (voir enregistrer()), dans l'ordre de ses lignes. Son prix de
     * vente TTC est celui de sa fiche, sinon celui que le barème actif du
     * magasin prévoit pour son prix d'achat, remise déduite ; sans l'un ni
     * l'autre, la validation est refusée. Il naît rattaché au fournisseur
     * de la facture, à son coût réel (voir LigneAchat::coutUnitaireHt()) ;
     * sa référence, saisie ou générée (voir Produit), est prise à ce
     * moment-là : les références automatiques suivent l'ordre des
     * validations, et une référence saisie qu'un autre produit a prise
     * entre-temps est refusée (voir Produit::verifierQueSaReferenceEstLibre()).
     * La ligne reçoit alors le produit et sa référence.
     *
     * @throws LogicException
     */
    public function creerLesNouveauxProduits(FactureAchat $factureAchat): void
    {
        foreach ($factureAchat->lignes()->whereNull('produit_id')->get() as $ligne) {
            $produit = new Produit($ligne->nouveau_produit);

            if (blank($produit->prix_vente_ttc)) {
                $produit->prix_vente_ttc = $this->prixDuBareme($factureAchat->magasin, (float) $ligne->prix_unitaire_ht, (float) $ligne->remise_pourcentage, (float) $produit->taux_tva)
                    ?? throw new LogicException("Indiquez le prix de vente TTC du nouveau produit « {$produit->nom} » avant de valider la facture d'achat.");
            }

            $produit->forceFill(['fournisseur_id' => $factureAchat->fournisseur_id, 'cmup_ht' => $ligne->coutUnitaireHt()])->save();

            $ligne->update(['produit_id' => $produit->id, 'reference' => $produit->reference, 'nouveau_produit' => null]);
        }
    }

    /**
     * Vérifie la fiche de chaque nouveau produit une fois toutes les lignes
     * enregistrées, et les fiches des produits existants corrigées : quel
     * que soit l'ordre des lignes, sa référence n'est ni celle d'un produit
     * du catalogue, archivé ou corrigé par une autre ligne compris (voir
     * Produit::verifierQueSaReferenceEstLibre()), ni celle d'un autre
     * nouveau produit de la facture, sans tenir compte de la casse (voir
     * Produit::normaliserReference()).
     *
     * Le droit de créer des produits se demande à qui saisit une fiche
     * nouvelle ou changée (voir verifierLeDroitDEnregistrer()). Reprendre
     * telle quelle une fiche que le brouillon gardait déjà ($fichesDejaSaisies)
     * ne crée rien de plus que ce que sa saisie a autorisé : un membre qui
     * ne peut pas créer de produits corrige les autres lignes d'un
     * brouillon, ou le valide, sans toucher au nouveau produit qu'un
     * collègue y a saisi ; il ne change pas cette fiche et ne la recopie
     * pas sur une seconde ligne, chaque fiche gardée ne servant qu'une fois.
     *
     * @param  list<LigneAchat>  $lignes
     * @param  list<array<string, mixed>>  $fichesDejaSaisies  voir ficheComparable()
     */
    private function verifierLesNouveauxProduits(User $auteur, array $lignes, array $fichesDejaSaisies): void
    {
        $referencesDesNouveauxProduits = [];

        foreach ($lignes as $ligne) {
            if ($ligne->nouveau_produit === null) {
                continue;
            }

            $produit = new Produit($ligne->nouveau_produit);
            $ficheDejaSaisie = array_search(self::ficheComparable($ligne->nouveau_produit), $fichesDejaSaisies, true);

            if ($ficheDejaSaisie === false) {
                $this->verifierLeDroitDEnregistrer($auteur, $produit);
            } else {
                unset($fichesDejaSaisies[$ficheDejaSaisie]);
            }

            if (blank($produit->reference)) {
                continue;
            }

            $produit->verifierQueSaReferenceEstLibre();
            $cle = Produit::normaliserReference($produit->reference);

            if (in_array($cle, $referencesDesNouveauxProduits, true)) {
                throw new InvalidArgumentException("Deux nouveaux produits de cette facture d'achat ont la même référence « {$produit->reference} ».");
            }

            $referencesDesNouveauxProduits[] = $cle;
        }
    }

    /**
     * La fiche d'un nouveau produit (voir ficheDuNouveauProduit()), ses
     * attributs dans un ordre fixe, pour la comparer strictement à une
     * autre.
     *
     * @param  array<string, mixed>  $fiche
     * @return array<string, mixed>
     */
    private static function ficheComparable(array $fiche): array
    {
        ksort($fiche);

        return $fiche;
    }

    /**
     * @param  array<string, mixed>  $donnees
     */
    private function enregistrerUneLigne(User $auteur, FactureAchat $factureAchat, array $donnees, int $ordre): LigneAchat
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

        // Sans prix de vente donné, un nouveau produit prend celui du barème
        // du magasin (voir BaremePrixDeVente), s'il en prévoit un à ce prix
        // d'achat ; sa fiche est vérifiée avec celles des autres lignes
        // (voir verifierLesNouveauxProduits()).
        if (! $produit->exists) {
            if (blank($produit->prix_vente_ttc)) {
                $produit->prix_vente_ttc = $this->prixDuBareme($factureAchat->magasin, $prixUnitaireHt, $remise, (float) $produit->taux_tva);
            }
        } elseif ($produit->isDirty()) {
            $this->verifierLeDroitDEnregistrer($auteur, $produit);
            $produit->save();
        }

        return $factureAchat->lignes()->create([
            'produit_id' => $produit->id,
            'nouveau_produit' => $produit->exists ? null : self::ficheDuNouveauProduit($produit),
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

    /**
     * Le prix de vente TTC que le barème actif du magasin prévoit pour ce
     * prix d'achat, remise déduite (voir BaremePrixDeVente::prixDeVente()) ;
     * null sans barème actif ou au-delà de son dernier palier.
     */
    private function prixDuBareme(Magasin $magasin, float $prixUnitaireHt, float $remise, float $tauxTva): ?float
    {
        return $magasin->baremePrixDeVenteActif()?->prixDeVente($this->calculateur->coutUnitaireNet($prixUnitaireHt, $remise), $tauxTva)['prix_vente_ttc'] ?? null;
    }

    /**
     * La fiche qu'un brouillon garde pour le nouveau produit, telle que le
     * produit la lira (ses casts : prix et taux au centième, suivi du
     * stock en booléen), sans les attributs laissés vides, qui prendront
     * leur valeur par défaut. Le suivi du stock et le seuil d'alerte y
     * figurent toujours, à leur valeur par défaut quand la ligne ne les
     * donne pas (voir Produit::$attributes) : deux saisies du même produit
     * donnent la même fiche (voir verifierLesNouveauxProduits()).
     *
     * @return array<string, mixed>
     */
    private static function ficheDuNouveauProduit(Produit $produit): array
    {
        return array_filter($produit->only(self::ATTRIBUTS_DU_PRODUIT), fn (mixed $valeur): bool => filled($valeur));
    }

    /**
     * L'auteur de la facture d'achat, relu par son action (voir
     * LecteurDesDroits), crée-t-il ou corrige-t-il ce produit ? Toujours
     * contrôlé : il n'y a pas d'enregistrement sans auteur.
     *
     * @throws LogicException
     */
    private function verifierLeDroitDEnregistrer(User $auteur, Produit $produit): void
    {
        $utilisateur = Gate::forUser($auteur);

        if (! $produit->exists && $utilisateur->denies('create', Produit::class)) {
            throw new LogicException("Vous ne pouvez pas créer de produit : « {$produit->nom} » doit d'abord être ajouté au catalogue.");
        }

        if ($produit->exists && $utilisateur->denies('update', $produit)) {
            throw new LogicException("Vous ne pouvez pas modifier la fiche du produit « {$produit->getOriginal('nom')} » : seule la ligne se modifie ici.");
        }
    }

    /**
     * Le produit existant, avec les corrections apportées depuis la ligne,
     * pas encore enregistrées ; ou la fiche du nouveau produit, au taux de
     * TVA par défaut du magasin si la ligne n'en donne pas.
     *
     * @param  array<string, mixed>  $donnees
     */
    private function produitDeLaLigne(FactureAchat $factureAchat, array $donnees): Produit
    {
        $attributs = self::attributsDuProduit($donnees);

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
            'taux_tva' => $donnees['taux_tva'] ?? $factureAchat->magasin->tauxTvaParDefaut(),
            ...$attributs,
        ]);
    }

    /**
     * Ce que la ligne dit de son produit : les attributs qu'elle renseigne,
     * et ceux qu'elle peut effacer.
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, mixed>
     */
    private static function attributsDuProduit(array $donnees): array
    {
        return array_filter(
            Arr::only($donnees['produit'] ?? [], self::ATTRIBUTS_DU_PRODUIT),
            fn (mixed $valeur, string $attribut): bool => filled($valeur) || in_array($attribut, self::ATTRIBUTS_EFFACABLES, true),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
