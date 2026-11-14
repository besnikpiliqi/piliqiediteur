<?php

declare(strict_types=1);

namespace App\Domain\Facturation\Services;

use App\Domain\Catalogue\Models\Produit;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Facturation\Enums\NatureLigne;
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use LogicException;
use UnexpectedValueException;

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
 * CalculateurLigne::chiffrerUneVente()) : la TVA de chaque ligne
 * s'arrondit une fois sur son TTC, puis la somme de chaque taux se ramène
 * à la TVA de son TTC d'un centime par ligne au plus ; les lignes ne se
 * calculent donc qu'ensemble (voir chiffrer()). Un même panier a les mêmes
 * montants pour tout client. Un produit n'y a qu'une ligne, un service qu'une ligne par
 * prix et par taux (voir refuserUnArticleSurDeuxLignes()).
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
     * @param  array<array-key, array<string, mixed>>  $lignesDonnees
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
            $produit = filled($donnees['produit_id'] ?? null) ? self::ficheDesignee($produits, $donnees['produit_id']) : null;

            if ($produit?->trashed()) {
                throw new LogicException("Le produit « {$produit->nom} » est archivé : restaurez-le depuis le catalogue, ou retirez-le du document.");
            }

            $lignes[] = $this->construire($donnees, $index, $contexte, $produits, $services);
        }

        self::refuserUnArticleSurDeuxLignes($lignes);

        return $this->chiffrer($lignes);
    }

    /**
     * Un produit n'a qu'une ligne d'un ticket, d'une facture ou d'un devis,
     * et un service qu'une ligne par prix et par taux (décision de
     * l'éditeur du 26/10/2026, série 9) : le choisir de nouveau augmente la
     * quantité de sa ligne (voir AjouteDesLignesDeVente), et l'enregistrement
     * refuse ce que l'écran ne doit jamais envoyer, d'où que viennent les
     * lignes (écran, panier de la caisse, API, requête forgée).
     *
     * @param  list<array<string, mixed>>  $lignes  voir construire()
     *
     * @throws InvalidArgumentException
     */
    private static function refuserUnArticleSurDeuxLignes(array $lignes): void
    {
        $refus = self::articleSurDeuxLignes($lignes);

        if ($refus !== null) {
            throw new InvalidArgumentException($refus);
        }
    }

    /**
     * Pourquoi ces lignes construites (voir construire() et apercu()) ne
     * s'enregistrent pas : un produit sur deux lignes, quelles que soient
     * leurs conditions, ou un service sur deux lignes au même prix et au
     * même taux ; null sinon. Seul un service vendu à deux prix ou à deux
     * taux, comme un service sans prix de référence saisi à 60,00 € puis à
     * 80,00 €, a plusieurs lignes ; une remise ne fait jamais une seconde
     * ligne. Les prix et les taux se comparent tels que les lignes
     * construites les écrivent, dans cette vente. L'écran le lit avant
     * d'envoyer le document (voir AjouteDesLignesDeVente::lignesDeVente()).
     *
     * @param  array<array-key, array<string, mixed>>  $lignes
     */
    public static function articleSurDeuxLignes(array $lignes): ?string
    {
        $articles = [];

        foreach ($lignes as $ligne) {
            $estUnProduit = $ligne['produit_id'] !== null;
            $article = $estUnProduit
                ? 'produit:'.ValeurSimple::enTexte($ligne['produit_id'])
                : 'service:'.ValeurSimple::enTexte($ligne['service_id']).'|'.ValeurSimple::enTexte($ligne['prix_unitaire_ttc']).'|'.ValeurSimple::enTexte($ligne['taux_tva_du_prix']);

            if (isset($articles[$article])) {
                $nom = ValeurSimple::enTexte($ligne['description']);

                return $estUnProduit
                    ? "« {$nom} » figure sur deux lignes : un produit n'a qu'une ligne, faites-en une seule de la quantité totale."
                    : "« {$nom} » figure sur deux lignes au même prix et au même taux : faites-en une seule ligne de la quantité totale.";
            }

            $articles[$article] = true;
        }

        return null;
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
        return DocumentChiffre::depuisLeCalcul($lignes, $this->calculateur->chiffrerUneVente(array_map(
            fn (array $ligne): array => [
                'prix_unitaire_ttc' => $this->calculateur->enCentiemes($ligne['prix_unitaire_ttc']),
                'quantite' => ValeursLues::entier($ligne['quantite']),
                'remise_pourcentage' => $this->calculateur->enCentiemes($ligne['remise_pourcentage']),
                'taux_tva_du_prix' => $this->calculateur->enCentiemes($ligne['taux_tva_du_prix']),
                'taux_tva' => $this->calculateur->enCentiemes($ligne['taux_tva']),
            ],
            $lignes,
        )), $this->calculateur);
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
     * @param  array<array-key, mixed>  $donnees  la ligne telle que l'écran, l'API ou un panier de la caisse la saisit (produit_id, service_id, quantite, prix_unitaire_ttc, taux_tva, remise_pourcentage), chaque valeur lue par sa vérification
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
            ? (self::ficheDesignee($produits, $donnees['produit_id']) ?? throw (new ModelNotFoundException)->setModel(Produit::class, [ValeursLues::cleDeTableau($donnees['produit_id'])]))
            : null;

        $service = filled($donnees['service_id'] ?? null)
            ? (self::ficheDesignee($services, $donnees['service_id']) ?? throw (new ModelNotFoundException)->setModel(Service::class, [ValeursLues::cleDeTableau($donnees['service_id'])]))
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

        $quantite = QuantiteEntiere::depuisSaisie($donnees['quantite']);

        $prixUnitaireTtc = filled($prixSaisi)
            ? SaisieDecimale::enCentiemes($prixSaisi)
            : $this->calculateur->enCentiemes($produit !== null ? $produit->prix_vente_ttc : ($service->prix_ttc ?? throw new InvalidArgumentException("Le prix du service « {$service->nom} » n'est pas fixé : demandez à un responsable.")));

        if ($prixUnitaireTtc < 0) {
            throw new InvalidArgumentException('Le PU TTC d\'une ligne ne peut pas être négatif.');
        }

        // Une remise hors de 0-100 % est refusée avec les montants (voir
        // chiffrer()).
        $remise = filled($donnees['remise_pourcentage'] ?? null) ? SaisieDecimale::enCentiemes($donnees['remise_pourcentage']) : 0;

        return [
            'produit_id' => $produit?->id,
            'service_id' => $service?->id,
            'nature' => $produit !== null ? NatureLigne::Bien : NatureLigne::Service,
            'description' => $produit !== null ? $produit->nom : $service->nom,
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
     * @param  array<array-key, array<array-key, mixed>>  $lignes
     * @return array{Collection<int, Produit>, Collection<int, Service>}
     */
    private function catalogue(array $lignes): array
    {
        $identifiants = fn (string $cle): array => collect($lignes)
            ->pluck($cle)
            ->map(fn (mixed $identifiant): ?int => self::identifiant($identifiant))
            ->filter(fn (?int $identifiant): bool => $identifiant !== null)
            ->unique()
            ->values()
            ->all();

        $produits = $identifiants('produit_id');
        $services = $identifiants('service_id');

        return [
            $produits === [] ? new Collection : Produit::withTrashed()->whereKey($produits)->get()->keyBy(fn (Produit $produit): int => CleDuModele::de($produit))->toBase(),
            $services === [] ? new Collection : Service::query()->whereKey($services)->get()->keyBy(fn (Service $service): int => CleDuModele::de($service))->toBase(),
        ];
    }

    /**
     * La fiche que désigne l'identifiant d'une ligne, cherché parmi celles
     * du catalogue sous la clé de tableau que PHP en fait, comme le fait
     * Collection::get() (voir ValeursLues::cleDeTableau()) : un entier, ou
     * un texte qui l'écrit comme PHP écrit un entier (« 12 », jamais
     * « +12 », « 012 » ni « 12 » suivi d'une espace) ; un booléen ou un
     * nombre décimal, que seule une requête forgée envoie, sous l'entier
     * que PHP en fait (vrai désigne la fiche 1, 12,5 la fiche 12) ; null
     * quand aucune ne porte cette clé.
     *
     * @template TFiche of Produit|Service
     *
     * @param  Collection<int, TFiche>  $fiches
     * @return TFiche|null
     *
     * @throws UnexpectedValueException un identifiant qui n'est pas une seule clé (un tableau, un objet)
     */
    private static function ficheDesignee(Collection $fiches, mixed $identifiant): Produit|Service|null
    {
        return $fiches->all()[ValeursLues::cleDeTableau($identifiant)] ?? null;
    }

    /**
     * L'identifiant d'une fiche choisie dans une ligne, lu comme l'entier
     * qu'il écrit (« 12 » ou 12, ou encore « +12 » ou « 12 » entouré
     * d'espaces, comme les lit filter_var()), ou null pour toute autre
     * valeur : les fiches du catalogue chargées pour les lignes.
     */
    private static function identifiant(mixed $valeur): ?int
    {
        $identifiant = filter_var($valeur, FILTER_VALIDATE_INT);

        return $identifiant === false ? null : $identifiant;
    }
}
