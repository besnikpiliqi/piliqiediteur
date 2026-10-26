<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Models\PanierCaisse;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Domain\Tenancy\Services\TenantContext;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;

/**
 * Le panier de la caisse de chaque vendeur, enregistré côté serveur à
 * mesure qu'il se remplit (voir Caisse) : une vente commencée n'existe pas
 * seulement dans la page. Recharger la page, en changer ou fermer l'onglet
 * ne l'efface pas : la caisse la reprend à sa réouverture. Le panier
 * enregistré ne perd un article que de trois façons, chacune tracée :
 *  - la vente est encaissée : le ticket est signé, et le panier se vide
 *    dans la même transaction (voir EnregistrerVenteCaisseAction) ;
 *  - la vente est abandonnée, par « Abandonner la vente » ou, restée
 *    ouverte, à la clôture de sa journée (voir CloturerCaisseAction) : JET
 *    320 ;
 *  - un article est retiré avant l'encaissement, ligne retirée, produit
 *    ou service remplacé, quantité baissée (voir LignesRetirees) : JET 323.
 *
 * Un article dont le prix baisse avant l'encaissement s'inscrit aussi en
 * ligne retirée (JET 323), avec son prix d'avant et d'après (Q8). Le
 * panier s'enregistrant à chaque requête, frappe après frappe, chaque
 * article garde son prix arrêté, celui que sa saisie a laissé la dernière
 * fois qu'elle était terminée (voir LignesRetirees::dansLePanier()) : la
 * baisse s'inscrit une seule fois, quand le nouveau prix s'arrête à son
 * tour (inchangé à l'enregistrement suivant) ou quand la vente se fige
 * (encaissement, abandon, clôture), avec le prix arrêté d'avant et le
 * prix final. Monter un prix en le retapant chiffre par chiffre ne baisse
 * donc rien, et un prix effacé puis retapé plus bas se compare au prix
 * d'avant son effacement : un prix illisible (vide, pas un nombre) garde
 * le dernier prix lisible de la ligne, comme une quantité illisible.
 *
 * Toutes ces écritures se font sous le verrou du registre fiscal du
 * magasin, puis du panier : un panier ouvert dans deux onglets ne perd
 * rien entre les deux sans trace. Celles que demande le vendeur relisent
 * en plus, entre les deux, le magasin et le vendeur (voir sousVerrou()) :
 * un magasin fermé ou un vendeur qui ne peut plus vendre n'écrit plus
 * rien.
 *
 * Le panier porte le jeton de sa vente (R3, voir ActionsUniques), que la
 * page de la caisse lui donne à sa création et reprend de lui ensuite :
 * l'encaissement le consomme. Un panier ne se recrée jamais sous un jeton
 * déjà consommé : après une réponse perdue, la caisse qui renvoie la même
 * vente retrouve le ticket déjà enregistré, au lieu d'en faire un second.
 */
class PanierDeCaisse
{
    /**
     * Le refus d'un panier dont le nombre d'articles dépasserait le plus
     * grand entier : il ne se compterait plus (voir nombreDArticles()).
     */
    public const TROP_D_ARTICLES = 'Le panier compterait plus d\'articles qu\'une vente ne peut en compter : baissez une quantité.';

    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly LignesRetirees $lignesRetirees,
        private readonly CalculateurLigne $calculateur,
        private readonly ConstructeurLigneDocument $constructeurLigne,
        private readonly ActionsUniques $actionsUniques,
        private readonly LecteurDesDroits $lecteur,
        private readonly OperateurCourant $operateur,
    ) {}

    /**
     * Le panier en cours du vendeur dans le magasin actif, null s'il n'en a
     * aucun.
     */
    public function enCours(User $vendeur): ?PanierCaisse
    {
        return PanierCaisse::query()->where('user_id', $vendeur->getKey())->first();
    }

    /**
     * Enregistre le panier tel que le vendeur le voit : son client, s'il
     * est du magasin, et ses lignes où un produit ou un service est choisi.
     * Chaque article qu'il a perdu depuis son dernier enregistrement, et
     * chaque prix qui s'arrête plus bas que le prix arrêté de son article,
     * s'inscrit au journal des événements (JET 323) ; un panier vide
     * s'efface. Une quantité en cours de saisie (vide, à virgule, nulle)
     * garde celle qu'avait la ligne : elle ne retire rien tant qu'elle
     * n'est pas un nombre d'articles ; un prix en cours de saisie (vide,
     * pas un nombre) garde de même celui qu'avait la ligne, sans
     * s'arrêter.
     *
     * Un nouveau panier prend le jeton que donne la caisse, sauf si ce
     * jeton a déjà été consommé : sa vente est encaissée, et le panier ne
     * se recrée pas.
     *
     * @param  string  $jeton  le jeton de la vente que montre la caisse
     * @param  array<array-key, mixed>  $lignes  les lignes du formulaire de la caisse, par clé du répéteur
     * @return PanierCaisse|null le panier enregistré, dont la caisse reprend le jeton ; null sans panier
     *
     * @throws InvalidArgumentException quand le jeton n'est pas un identifiant unique, ou quand le panier compterait plus d'articles qu'un entier n'en compte
     * @throws AuthorizationException quand le vendeur ne peut plus vendre dans ce magasin
     * @throws LogicException quand le magasin est en lecture seule
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function enregistrer(User $vendeur, string $jeton, mixed $clientId, array $lignes): ?PanierCaisse
    {
        if (! Str::isUuid($jeton)) {
            throw new InvalidArgumentException('La caisse a perdu le jeton de sa vente : rechargez la page.');
        }

        $enCours = $this->enCours($vendeur);
        $clientId = $this->clientDuMagasin($clientId);

        if ($enCours !== null && $enCours->client_id === $clientId && $enCours->lignes === $this->lignesAEnregistrer($lignes, $enCours->lignes) && ! $this->aDesPrixAArreter($enCours)) {
            return $enCours;
        }

        if ($enCours === null && ($this->lignesAEnregistrer($lignes, []) === [] || $this->actionsUniques->aServi($jeton))) {
            return null;
        }

        return $this->sousVerrou($vendeur, function (User $vendeur) use ($jeton, $clientId, $lignes): ?PanierCaisse {
            $panier = PanierCaisse::query()->where('user_id', $vendeur->getKey())->lockForUpdate()->first();
            $nouvelles = $this->lignesAEnregistrer($lignes, $panier->lignes ?? []);
            $prixArretes = $this->suivre($panier, $nouvelles, $this->prixEnSaisie($lignes), false, ['origine' => 'caisse']);

            if ($nouvelles === []) {
                $panier?->delete();

                return null;
            }

            if ($panier === null && $this->actionsUniques->aServi($jeton)) {
                return null;
            }

            return tap(($panier ?? (new PanierCaisse)->forceFill(['user_id' => $vendeur->getKey(), 'jeton' => $jeton]))
                ->forceFill(['client_id' => $clientId, 'lignes' => $nouvelles, 'prix_arretes' => $prixArretes]))
                ->save();
        });
    }

    /**
     * Abandonne la vente en cours du vendeur, telle qu'il la voit : ce
     * qu'elle a perdu depuis son dernier enregistrement et chaque baisse
     * d'un prix pas encore arrêté d'abord (JET 323), puis son abandon, avec
     * son nombre de lignes, son nombre d'articles et son total TTC en
     * centimes (JET 320), comme l'annonce la fenêtre de confirmation de la
     * caisse. Le panier s'efface.
     *
     * @param  string  $jeton  le jeton de la vente que montre la caisse
     * @param  array<array-key, mixed>  $lignes  les lignes du formulaire de la caisse, par clé du répéteur
     *
     * @throws AuthorizationException quand le vendeur ne peut plus vendre dans ce magasin
     * @throws LogicException quand le magasin est en lecture seule
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function abandonner(User $vendeur, string $jeton, mixed $clientId, array $lignes): void
    {
        $this->sousVerrou($vendeur, function (User $vendeur) use ($jeton, $clientId, $lignes): void {
            $this->enregistrer($vendeur, $jeton, $clientId, $lignes);

            $panier = PanierCaisse::query()->where('user_id', $vendeur->getKey())->lockForUpdate()->first();

            if ($panier === null) {
                return;
            }

            $this->suivre($panier, $panier->lignes, [], true, ['origine' => 'caisse']);
            $this->registre->journaliser(CodeEvenement::TransactionAbandonnee, [
                'origine' => 'caisse',
                'nombre_lignes' => count($panier->lignes),
                'nombre_articles' => self::nombreDArticles($panier->lignes),
                'total_ttc' => $this->totalTtcEnCentimes($panier),
            ]);

            $panier->delete();
        });
    }

    /**
     * Exécute une écriture du panier demandée par son vendeur, sous le
     * verrou du registre fiscal du magasin, premier verrou de sa
     * transaction, que prennent aussi la fermeture du magasin (voir
     * FermerMagasinAction), qui abandonne les ventes ouvertes, et toute
     * modification des droits du vendeur (voir JournalDesDroits) : la
     * ligne du magasin se relit ensuite sous un verrou partagé (un magasin
     * suspendu, fermé ou cédé, ou dont la société est en lecture seule,
     * n'enregistre plus de panier, voir Magasin::verifierQuIlEstOuvert()),
     * puis le vendeur sous le verrou de sa ligne, qui doit encore pouvoir
     * vendre dans ce magasin (voir LecteurDesDroits). Une fermeture ou un
     * droit retiré validé pendant que la caisse restait ouverte est vu :
     * aucun panier ne se recrée après la fermeture du magasin, et aucune
     * ligne retirée ni vente abandonnée ne s'inscrit après elle, ni au nom
     * d'un vendeur qui ne peut plus vendre. L'écriture reçoit le vendeur
     * relu et s'inscrit à son nom (voir OperateurCourant).
     *
     * @template TResultat
     *
     * @param  Closure(User): TResultat  $ecriture
     * @return TResultat
     *
     * @throws AuthorizationException quand le vendeur ne peut plus vendre dans ce magasin
     * @throws LogicException quand le magasin est en lecture seule
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    private function sousVerrou(User $vendeur, Closure $ecriture): mixed
    {
        $magasin = app(TenantContext::class)->magasin();

        return $this->registre->sousVerrou($magasin, function () use ($vendeur, $ecriture, $magasin): mixed {
            Magasin::query()->whereKey($magasin->getKey())->sharedLock()->firstOrFail()->verifierQuIlEstOuvert();
            $relu = $this->lecteur->exigerSousVerrou($vendeur, PermissionRegistry::FACTURE_CREATE, $magasin);

            return $this->operateur->pendant($relu, fn (): mixed => $ecriture($relu));
        });
    }

    /**
     * Les ventes restées ouvertes à la clôture de leur journée sont
     * abandonnées : chaque panier enregistré pour la dernière fois ce
     * jour-là ou avant inscrit au journal des événements chaque baisse
     * d'un prix pas encore arrêté (JET 323), puis son abandon (JET 320),
     * avec son vendeur, son nombre de lignes, son nombre d'articles et son
     * total TTC en centimes, et s'efface. À appeler dans la transaction de
     * la clôture, qui tient déjà le verrou du registre fiscal (voir
     * CloturerCaisseAction).
     *
     * @param  string  $journee  la journée clôturée, AAAA-MM-JJ
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function abandonnerLesVentesOuvertesLe(string $journee): void
    {
        $this->ventesOuvertesLe($journee)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->each(function (PanierCaisse $panier) use ($journee): void {
                $origine = ['origine' => 'cloture', 'journee' => $journee, 'vendeur_id' => (int) $panier->user_id];
                $this->suivre($panier, $panier->lignes, [], true, $origine);
                $this->registre->journaliser(CodeEvenement::TransactionAbandonnee, [
                    ...$origine,
                    'nombre_lignes' => count($panier->lignes),
                    'nombre_articles' => self::nombreDArticles($panier->lignes),
                    'total_ttc' => $this->totalTtcEnCentimes($panier),
                ]);

                $panier->delete();
            });
    }

    /**
     * Le nombre de ventes que la clôture de la journée abandonnerait (voir
     * abandonnerLesVentesOuvertesLe()), pour le dire avant de clôturer.
     *
     * @param  string  $journee  AAAA-MM-JJ
     */
    public function nombreDeVentesOuvertesLe(string $journee): int
    {
        return $this->ventesOuvertesLe($journee)->count();
    }

    /**
     * Les paniers du magasin actif enregistrés pour la dernière fois ce
     * jour de son calendrier ou avant.
     *
     * @return Builder<PanierCaisse>
     */
    private function ventesOuvertesLe(string $journee): Builder
    {
        return PanierCaisse::query()->where('updated_at', '<', app(TenantContext::class)->magasin()->calendrier()->bornesDuJour($journee)[1]);
    }

    /**
     * La vente du panier du vendeur est encaissée, dans la transaction en
     * cours, qui tient déjà le verrou du registre fiscal (voir
     * EnregistrerVenteCaisseAction) : ce que le panier perd jusqu'aux
     * lignes vendues, et chaque prix vendu plus bas que le prix arrêté de
     * son article, s'inscrit au journal des événements (JET 323), puis le
     * panier se vide.
     *
     * @param  array<array-key, mixed>  $lignesVendues  les lignes de la vente encaissée
     *
     * @throws InvalidArgumentException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function encaisser(User $vendeur, array $lignesVendues): void
    {
        $panier = PanierCaisse::query()->where('user_id', $vendeur->getKey())->lockForUpdate()->first();

        if ($panier === null) {
            return;
        }

        $this->suivre($panier, $this->lignesAEnregistrer($lignesVendues, $panier->lignes), [], true, ['origine' => 'caisse']);
        $panier->delete();
    }

    /**
     * Le client choisi, s'il est un client du magasin actif (MagasinScope),
     * archivé compris : sinon aucun.
     */
    private function clientDuMagasin(mixed $clientId): ?int
    {
        $identifiant = self::identifiant($clientId);

        return $identifiant === null ? null : Client::withTrashed()->whereKey($identifiant)->value('id');
    }

    /**
     * Les lignes où un produit ou un service est choisi, par clé du
     * répéteur, réduites à des valeurs simples ; une quantité qui n'est pas
     * encore un nombre d'articles garde celle de la ligne enregistrée sous
     * la même clé pour le même article, sinon compte pour un article ; un
     * PU TTC illisible (voir LignesRetirees::prixEnCentimes()) garde de
     * même celui de cette ligne, sinon reste vide. Le nombre d'articles des
     * lignes ne dépasse jamais le plus grand entier : un panier qui le
     * dépasserait est refusé.
     *
     * @param  array<array-key, mixed>  $lignes
     * @param  array<string, array<string, mixed>>  $enregistrees
     * @return array<string, array{produit_id: int|null, service_id: int|null, description: string|null, reference: string|null, quantite: int, prix_unitaire_ht: string|null, prix_unitaire_ttc: string|null, taux_tva: string|null, remise_pourcentage: string|null}>
     *
     * @throws InvalidArgumentException
     */
    private function lignesAEnregistrer(array $lignes, array $enregistrees): array
    {
        $resultat = [];

        foreach ($lignes as $cle => $ligne) {
            if (! is_array($ligne)) {
                continue;
            }

            $produitId = self::identifiant($ligne['produit_id'] ?? null);
            $serviceId = $produitId === null ? self::identifiant($ligne['service_id'] ?? null) : null;

            if ($produitId === null && $serviceId === null) {
                continue;
            }

            $precedente = $enregistrees[(string) $cle] ?? null;
            $memeArticle = $precedente !== null && $precedente['produit_id'] === $produitId && $precedente['service_id'] === $serviceId;

            try {
                $quantite = QuantiteEntiere::depuisSaisie($ligne['quantite'] ?? null);
            } catch (InvalidArgumentException) {
                $quantite = $memeArticle ? $precedente['quantite'] : 1;
            }

            $prixLisible = $this->lignesRetirees->prixEnCentimes($ligne['prix_unitaire_ttc'] ?? null) !== null;

            $resultat[(string) $cle] = [
                'produit_id' => $produitId,
                'service_id' => $serviceId,
                'description' => self::texte($ligne['description'] ?? null),
                'reference' => self::texte($ligne['reference'] ?? null),
                'quantite' => $quantite,
                'prix_unitaire_ht' => $prixLisible || ! $memeArticle ? self::texte($ligne['prix_unitaire_ht'] ?? null) : $precedente['prix_unitaire_ht'],
                'prix_unitaire_ttc' => $prixLisible || ! $memeArticle ? self::texte($ligne['prix_unitaire_ttc'] ?? null) : $precedente['prix_unitaire_ttc'],
                'taux_tva' => self::texte($ligne['taux_tva'] ?? null),
                'remise_pourcentage' => self::texte($ligne['remise_pourcentage'] ?? null),
            ];
        }

        self::nombreDArticles($resultat);

        return $resultat;
    }

    /**
     * Les clés des lignes dont le PU TTC est en cours de saisie, illisible
     * (voir LignesRetirees::prixEnCentimes()) : leur prix ne s'arrête pas.
     *
     * @param  array<array-key, mixed>  $lignes  les lignes du formulaire de la caisse, par clé du répéteur
     * @return list<string>
     */
    private function prixEnSaisie(array $lignes): array
    {
        $cles = [];

        foreach ($lignes as $cle => $ligne) {
            if (is_array($ligne) && $this->lignesRetirees->prixEnCentimes($ligne['prix_unitaire_ttc'] ?? null) === null) {
                $cles[] = (string) $cle;
            }
        }

        return $cles;
    }

    /**
     * Suit le panier de son dernier enregistrement aux lignes $nouvelles
     * (voir LignesRetirees::dansLePanier()) : ce qu'il perd s'inscrit au
     * journal des événements (JET 323), avec $origine, et le prix arrêté
     * des articles de chaque nouvelle ligne est rendu, par clé. À appeler
     * sous le verrou du registre fiscal du magasin et du panier.
     *
     * @param  array<string, array<string, mixed>>  $nouvelles  les lignes à enregistrer (voir lignesAEnregistrer())
     * @param  list<string>  $prixEnSaisie  les clés des lignes dont le prix est en cours de saisie
     * @param  bool  $arreter  la vente se fige (encaissement, abandon, clôture) : chaque prix s'arrête
     * @param  array<string, int|string>  $origine  d'où vient la perte : la caisse, ou la clôture de telle journée et son vendeur
     * @return array<string, list<array{prix_unitaire_ttc: int|null, quantite: int}>>
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    private function suivre(?PanierCaisse $panier, array $nouvelles, array $prixEnSaisie, bool $arreter, array $origine): array
    {
        $avant = [];

        foreach ($panier->lignes ?? [] as $cle => $ligne) {
            $avant[] = [...$this->comparable($ligne), 'prix_arretes' => $panier->prix_arretes[$cle]];
        }

        $apres = [];

        foreach ($nouvelles as $cle => $ligne) {
            $apres[] = [...$this->comparable($ligne), 'en_saisie' => in_array((string) $cle, $prixEnSaisie, true)];
        }

        $suivi = $this->lignesRetirees->dansLePanier($avant, $apres, $arreter);

        foreach ($suivi['pertes'] as $perte) {
            $this->registre->journaliser(CodeEvenement::LigneRetiree, [...$origine, ...$perte]);
        }

        return array_combine(array_map('strval', array_keys($nouvelles)), $suivi['prix_arretes']);
    }

    /**
     * Un prix du panier n'est pas encore arrêté : l'article d'une ligne a
     * un prix arrêté autre que le prix de sa ligne. L'enregistrement
     * suivant l'arrête, s'il ne le change pas.
     */
    private function aDesPrixAArreter(PanierCaisse $panier): bool
    {
        foreach ($panier->lignes as $cle => $ligne) {
            $prix = $this->lignesRetirees->prixEnCentimes($ligne['prix_unitaire_ttc']);

            foreach ($panier->prix_arretes[$cle] as $arrete) {
                if ($arrete['prix_unitaire_ttc'] !== $prix) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $ligne  une ligne du panier (voir lignesAEnregistrer())
     * @return array{produit_id: int|null, service_id: int|null, description: string|null, quantite: int, prix_unitaire_ttc: int|null}
     */
    private function comparable(array $ligne): array
    {
        return $this->lignesRetirees->comparable($ligne['produit_id'], $ligne['service_id'], $ligne['description'], $ligne['quantite'], $ligne['prix_unitaire_ttc']);
    }

    /**
     * Le total TTC des lignes du panier, calculé comme à l'écran de la
     * caisse et à l'encaissement (voir ConstructeurLigneDocument::apercu()),
     * pour son client, en centimes ; zéro quand aucune ligne n'est complète.
     */
    private function totalTtcEnCentimes(PanierCaisse $panier): int
    {
        $contexte = ContexteDeVente::pour(Client::withTrashed()->find($panier->client_id), app(TenantContext::class)->magasin());

        return $this->calculateur->enCentiemes($this->constructeurLigne->apercu($panier->lignes, $contexte)->totaux['total_ttc']);
    }

    /**
     * Le nombre d'articles des lignes : la somme de leurs quantités,
     * additionnées une à une sans jamais dépasser le plus grand entier.
     *
     * @param  array<string, array{quantite: int}>  $lignes
     *
     * @throws InvalidArgumentException quand la somme le dépasserait
     */
    private static function nombreDArticles(array $lignes): int
    {
        $nombre = 0;

        foreach ($lignes as $ligne) {
            if ($ligne['quantite'] > PHP_INT_MAX - $nombre) {
                throw new InvalidArgumentException(self::TROP_D_ARTICLES);
            }

            $nombre += $ligne['quantite'];
        }

        return $nombre;
    }

    private static function identifiant(mixed $valeur): ?int
    {
        $identifiant = is_bool($valeur) ? false : filter_var($valeur, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $identifiant === false ? null : $identifiant;
    }

    /**
     * Une valeur saisie en texte, 255 caractères au plus ; null si elle est
     * vide ou n'est pas une valeur simple.
     */
    private static function texte(mixed $valeur): ?string
    {
        if (! is_scalar($valeur) || is_bool($valeur) || (string) $valeur === '') {
            return null;
        }

        return mb_substr((string) $valeur, 0, 255);
    }
}
