<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Achats\Enums\StatutFactureAchat;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Catalogue\Models\Produit;
use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Stock\Enums\StatutInventaireOuverture;
use App\Domain\Stock\Models\InventaireOuverture;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Enums\UrgenceAFaire;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\EcartSirene;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\PieceVerification;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Models\TransmissionPropriete;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Support\ChoseAFaire;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\Societe\Pages\VerificationSociete as VerificationSocietePage;
use App\Filament\Societe\Resources\MagasinResource;
use App\Filament\Societe\Resources\MembreResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ce qui reste à faire dans chaque magasin, au tableau de bord de l'espace
 * d'administration de la société (décision de l'éditeur du 26/10/2026,
 * règle 6 de l'« À faire », et du 01/11/2026 pour les avoirs non
 * utilisés) : une lecture hors du magasin courant, déclarée dans
 * CLAUDE.md, qui ne rend que des nombres par groupe (« 2 preuves à
 * fournir · 1 achat à valider »), et, pour les avoirs non utilisés, leur
 * montant restant ; aucun nom de client, aucun numéro de document. Un clic
 * ouvre le tableau de bord du magasin, où chaque chose se détaille (voir
 * ChosesAFaireDuMagasin).
 *
 * Elle ne lit que les magasins réels de la portée de la personne (voir
 * User::magasinsDeSaPortee()), jamais le magasin de démonstration ni un
 * magasin d'une autre société, et, dans chacun, seulement les groupes
 * dont elle voit tous les documents, d'après ses cases cochées dans ce
 * magasin (voir User::autorisationsDans() : toutes pour le propriétaire,
 * celles de sa sélection pour un administrateur) : les ventes (preuves,
 * visas de détaxe, brouillons de facture) et les avoirs quand elle voit
 * les factures de toute l'équipe, les devis quand elle les voit, les
 * achats, les inventaires et les pièces du fournisseur quand elle voit
 * les achats, le stock quand elle voit les produits (une société qui a
 * des articles) ; jamais les caisses ni les journées, que montre la page
 * « Les caisses ». Chaque groupe se lit en une seule requête pour tous
 * ces magasins, sans MagasinScope, aucun magasin n'étant actif dans
 * l'espace de la société : elle est filtrée explicitement par leurs
 * identifiants, ses sous-requêtes par le magasin de chaque ligne (voir
 * BelongsToMagasin::scopeDuMagasinDe()), et compte par magasin les
 * mêmes documents que sa liste et son « À faire », par les mêmes
 * filtres ; la page lit ainsi la base autant de fois avec un magasin ou
 * vingt. Les montants restants se calculent en centimes, depuis les
 * montants enregistrés, jamais par une somme que la base ferait sur une
 * colonne décimale.
 */
final class ChosesAFaireDeLaSociete
{
    public function __construct(private readonly CalculateurLigne $calculateur) {}

    /**
     * Les nombres de chaque magasin réel de sa portée, par identifiant de
     * magasin, dans l'ordre des magasins ; un groupe sans rien n'y figure
     * pas, et un magasin sans rien à faire a une liste vide.
     *
     * @return array<int, list<array{groupe: string, nombre: int, montant: string|null}>>
     */
    public function parMagasin(User $compte): array
    {
        if ($compte->entreParLesAutorisationsDUnCabinet()) {
            return [];
        }

        $magasins = $compte->magasinsDeSaPortee()->where('est_demonstration', false)->orderBy('id')->get()->keyBy('id');
        $avecArticles = $magasins->filter(fn (Magasin $magasin): bool => $magasin->societe->aDesArticles());
        $factures = $this->ou($magasins, $compte, PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES);
        $devis = $this->ou($magasins, $compte, PermissionRegistry::DEVIS_VIEW);
        $ventesDArticles = $this->ou($avecArticles, $compte, PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES);
        $achats = $this->ou($avecArticles, $compte, PermissionRegistry::FACTURE_ACHAT_VIEW);
        $avoirsFournisseur = $this->ou($avecArticles, $compte, PermissionRegistry::FACTURE_ACHAT_VIEW, PermissionRegistry::AVOIR_FOURNISSEUR_VIEW);
        $produits = $this->ou($avecArticles, $compte, PermissionRegistry::PRODUIT_VIEW);
        $credits = $this->avoirsNonUtilises($this->ou($magasins, $compte, PermissionRegistry::FACTURE_VIEW, PermissionRegistry::FACTURE_VIEW_TOUTES, PermissionRegistry::AVOIR_VIEW));
        $piecesDesAchats = $this->compter(FactureAchat::query()->where('piece_manquante', true), $achats);
        $piecesDesAvoirs = $this->compter(AvoirFournisseur::query()->where('statut', StatutAvoirFournisseur::Emise)->where('piece_manquante', true), $avoirsFournisseur);
        $pieces = [];

        foreach ($achats as $id) {
            $pieces[$id] = ($piecesDesAchats[$id] ?? 0) + ($piecesDesAvoirs[$id] ?? 0);
        }

        $groupes = [
            ['preuve à fournir', 'preuves à fournir', $this->compter(Facture::query()->preuvesAFournir(), $ventesDArticles)],
            ['visa de détaxe à noter', 'visas de détaxe à noter', $this->compter(Facture::query()->visaANoter(), $ventesDArticles)],
            ['brouillon de facture', 'brouillons de facture', $this->compter(Facture::query()->where('statut', StatutFacture::Brouillon), $factures)],
            ['devis en brouillon', 'devis en brouillon', $this->compter(Devis::query()->where('statut', StatutDevis::Brouillon), $devis)],
            ['devis qui expire', 'devis qui expirent', $this->devisQuiExpirent($magasins->only($devis))],
            ['devis à transformer', 'devis à transformer', $this->compter(Devis::query()->aTransformer(), $devis)],
            ['achat à valider', 'achats à valider', $this->compter(FactureAchat::query()->where('statut', StatutFactureAchat::Brouillon), $achats)],
            ['inventaire à valider', 'inventaires à valider', $this->compter(InventaireOuverture::query()->where('statut', StatutInventaireOuverture::Brouillon), $achats)],
            ['pièce du fournisseur manquante', 'pièces du fournisseur manquantes', $pieces],
            ['produit en stock négatif', 'produits en stock négatif', $this->compter(Produit::query()->enStockNegatif(), $produits)],
            ['produit en stock bas', 'produits en stock bas', $this->compter(Produit::query()->enStockBas(), $produits)],
        ];
        $nombres = [];

        foreach ($magasins as $magasin) {
            $sesNombres = [];

            foreach ($groupes as [$singulier, $pluriel, $parMagasin]) {
                $nombre = $parMagasin[$magasin->id] ?? 0;

                if ($nombre > 0) {
                    $sesNombres[] = ['groupe' => $nombre === 1 ? $singulier : $pluriel, 'nombre' => $nombre, 'montant' => null];
                }
            }

            $restants = $credits[$magasin->id] ?? [];

            if ($restants !== []) {
                $sesNombres[] = ['groupe' => count($restants) === 1 ? 'avoir non utilisé' : 'avoirs non utilisés', 'nombre' => count($restants), 'montant' => $this->calculateur->enEuros(array_sum($restants))];
            }

            $nombres[$magasin->id] = $sesNombres;
        }

        return $nombres;
    }

    /**
     * Ce qui attend dans la société elle-même, dans sa section « À faire »
     * (analyse de l'« À faire », § 4.4, et décision de l'éditeur du
     * 01/11/2026, Q-AF13), chacun pour qui peut le faire : pour le
     * propriétaire seul, le code du courrier recommandé et sa date limite,
     * le nouveau courrier qu'il peut demander, les pièces à déposer pour
     * l'éditeur, la transmission de la propriété qu'il a demandée, chaque
     * nomination d'un responsable par un administrateur (à garder ou à
     * annuler) et les comptes dont le mot de passe est encore
     * l'identifiant (la liste de l'équipe ne l'indique qu'à lui) ; pour qui
     * gère ce magasin (le propriétaire, ou un administrateur autorisé, dans
     * sa portée), chaque établissement pas encore confirmé et chaque
     * adresse de magasin qui manque pour facturer un professionnel ; pour
     * qui peut le dire vérifié, chaque écart relevé au répertoire Sirene
     * (voir PorteeDesDroits::peutVerifierLEcart()) ; pour l'administrateur
     * à qui la propriété est transmise, le lien à suivre. Dans l'ordre de
     * l'« À faire » (voir ChoseAFaire::comparer()), aux jours de Paris,
     * ceux de la société. Ne lit que cette société.
     *
     * @return list<ChoseAFaire>
     */
    public function delaSociete(User $compte, Societe $societe): array
    {
        if ($compte->entreParLesAutorisationsDUnCabinet() || $compte->societe_id !== $societe->getKey() || ! $compte->peutAgir()) {
            return [];
        }

        $paris = Calendrier::deParis();
        $choses = [];

        if ($compte->estProprietaire()) {
            array_push($choses, ...$this->courrierDeVerification($societe, $paris), ...$this->nominations($societe, $paris));
            $choses[] = $this->motsDePasseIdentifiants();
        }

        array_push($choses, ...$this->transmissions($compte, $societe, $paris), ...$this->magasinsAConfirmer($compte, $societe, $paris), ...$this->ecartsSirene($compte, $societe, $paris));
        $choses = array_values(array_filter($choses));
        usort($choses, ChoseAFaire::comparer(...));

        return $choses;
    }

    /**
     * Le code du courrier recommandé à saisir avant la clôture de
     * l'inscription, le nouveau courrier qu'il peut demander, et les pièces
     * à déposer une fois ce nouveau courrier parti.
     *
     * @return list<ChoseAFaire>
     */
    private function courrierDeVerification(Societe $societe, Calendrier $paris): array
    {
        if (! $societe->attendSonCode()) {
            return [];
        }

        $courriers = app(CourriersDeVerification::class);
        $cloture = $courriers->cloturePrevueLe($societe);
        $nouvelEnvoi = $courriers->nouvelEnvoiPossibleLe($societe);
        $verification = VerificationSocietePage::getUrl();
        $choses = [new ChoseAFaire(
            cle: 'code_du_courrier',
            groupe: 'Vérification',
            urgence: UrgenceAFaire::Rouge,
            nombre: 1,
            phrase: 'Saisissez le code du courrier recommandé avant le '.$paris->afficher($cloture, 'd/m/Y').' : sans lui, l\'inscription de votre société sera close.',
            url: $verification,
            bouton: 'Saisir le code',
            aSurveiller: false,
            date: $paris->jourDe($cloture),
        )];

        if ($nouvelEnvoi !== null && $nouvelEnvoi->lessThanOrEqualTo(now())) {
            $choses[] = new ChoseAFaire(
                cle: 'nouveau_courrier',
                groupe: 'Vérification',
                urgence: UrgenceAFaire::Orange,
                nombre: 1,
                phrase: 'Courrier non reçu ? Vous pouvez demander un nouveau courrier, une seule fois : le premier code ne vaudra plus.',
                url: $verification,
                bouton: 'Demander',
                aSurveiller: false,
                date: $paris->jourDe($nouvelEnvoi),
            );
        }

        if ($courriers->piecesPossibles($societe)) {
            $deposees = PieceVerification::query()->where('societe_id', $societe->getKey())->exists();
            $choses[] = new ChoseAFaire(
                cle: 'pieces_de_la_verification',
                groupe: 'Vérification',
                urgence: $deposees ? UrgenceAFaire::Gris : UrgenceAFaire::Orange,
                nombre: 1,
                phrase: $deposees ? 'Vos pièces attendent la décision de l\'éditeur.' : 'Déposez vos pièces pour l\'éditeur, qui traitera votre vérification à la main.',
                url: $verification,
                bouton: $deposees ? 'Voir' : 'Déposer',
                aSurveiller: $deposees,
            );
        }

        return $choses;
    }

    /**
     * Chaque nomination d'un responsable par un administrateur, que le
     * propriétaire garde ou annule depuis la fiche du compte (voir
     * GarderUneNominationAction et AnnulerUneNominationAction).
     *
     * @return list<ChoseAFaire>
     */
    private function nominations(Societe $societe, Calendrier $paris): array
    {
        return array_values(Affectation::query()
            ->where('societe_id', $societe->getKey())
            ->whereNotNull('nomme_par')
            ->with(['utilisateur:id,name', 'magasin:id,nom', 'nommePar:id,name'])
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Affectation $affectation): ChoseAFaire => new ChoseAFaire(
                cle: 'nomination',
                groupe: 'Équipe',
                urgence: UrgenceAFaire::Orange,
                nombre: 1,
                phrase: ($affectation->utilisateur->name ?? 'Un compte').' a été nommé responsable de '.($affectation->magasin->nom ?? 'son magasin').' par '.($affectation->nommePar->name ?? 'un administrateur').' : gardez ou annulez cette nomination.',
                url: MembreResource::getUrl('view', ['record' => $affectation->user_id]),
                bouton: 'Garder ou annuler',
                aSurveiller: false,
                date: $affectation->updated_at === null ? null : $paris->jourDe($affectation->updated_at),
            ))
            ->all());
    }

    /**
     * Les comptes de la société dont le mot de passe est encore
     * l'identifiant, que seul le propriétaire voit (voir
     * MembreResource::urlDesMotsDePasseIdentifiants()).
     */
    private function motsDePasseIdentifiants(): ?ChoseAFaire
    {
        $nombre = MembreResource::auMotDePasseIdentifiant(MembreResource::getEloquentQuery())->count();

        return $nombre === 0 ? null : new ChoseAFaire(
            cle: 'mots_de_passe_identifiants',
            groupe: 'Équipe',
            urgence: UrgenceAFaire::Gris,
            nombre: $nombre,
            phrase: trans_choice('{1} 1 compte a encore son identifiant pour mot de passe|[2,*] :count comptes ont encore leur identifiant pour mot de passe', $nombre).' : il n\'ouvre son compte que depuis un poste reconnu du magasin.',
            url: MembreResource::urlDesMotsDePasseIdentifiants(),
            bouton: 'Voir',
            aSurveiller: true,
        );
    }

    /**
     * La transmission de la propriété en attente : pour le propriétaire
     * qui l'a demandée (il peut l'annuler depuis la fiche du compte) et
     * pour l'administrateur qui la reçoit (il suit le lien de l'e-mail).
     *
     * @return list<ChoseAFaire>
     */
    private function transmissions(User $compte, Societe $societe, Calendrier $paris): array
    {
        $transmission = TransmissionPropriete::query()
            ->where('societe_id', $societe->getKey())
            ->whereNull('annulee_le')
            ->whereNull('acceptee_le')
            ->where('expire_le', '>', now())
            ->when(! $compte->estProprietaire(), fn (Builder $requete): Builder => $requete->where('destinataire_id', $compte->getKey()))
            ->with('destinataire:id,name')
            ->first();

        if ($transmission === null) {
            return [];
        }

        $avant = $paris->afficher($transmission->expire_le, 'd/m/Y à H:i');

        return [new ChoseAFaire(
            cle: 'transmission',
            groupe: 'Propriété',
            urgence: UrgenceAFaire::Orange,
            nombre: 1,
            phrase: $compte->estProprietaire()
                ? 'La propriété de la société attend que '.($transmission->destinataire->name ?? 'l\'administrateur').' suive le lien reçu par e-mail, avant le '.$avant.'.'
                : 'Le propriétaire vous transmet la propriété de la société : suivez le lien envoyé à votre adresse, connecté à votre compte, avant le '.$avant.'.',
            url: $compte->estProprietaire() ? MembreResource::getUrl('view', ['record' => $transmission->destinataire_id]) : null,
            bouton: $compte->estProprietaire() ? 'Voir' : 'Suivre le lien de l\'e-mail',
            aSurveiller: $compte->estProprietaire(),
            date: $paris->jourDe($transmission->expire_le),
        )];
    }

    /**
     * Les établissements pas encore confirmés au répertoire Sirene (aucune
     * vente avant), et les adresses de magasin qui manquent pour facturer
     * un professionnel, de chaque magasin réel ouvert de sa portée, pour
     * qui gère les magasins de la société : il modifie alors chacun d'eux
     * (voir MagasinDeLaSocietePolicy::update()), lus en une seule requête.
     *
     * @return list<ChoseAFaire>
     */
    private function magasinsAConfirmer(User $compte, Societe $societe, Calendrier $paris): array
    {
        if (! $compte->can('gererLesMagasins', $societe)) {
            return [];
        }

        $choses = [];
        $magasins = $compte->magasinsDeSaPortee()->where('est_demonstration', false)->where('statut', StatutMagasin::Actif->value)->orderBy('nom')->orderBy('id')->get();

        foreach ($magasins as $magasin) {
            if ($magasin->etablissement_verifie_le === null) {
                $choses[] = new ChoseAFaire(
                    cle: 'etablissement_a_confirmer',
                    groupe: 'Magasins',
                    urgence: UrgenceAFaire::Orange,
                    nombre: 1,
                    phrase: "L'établissement de {$magasin->nom} (SIRET {$magasin->siret}) n'est pas encore confirmé par le répertoire Sirene : aucune vente avant sa confirmation.",
                    url: $compte->estProprietaire() && ! $magasin->societe->estValidee() ? VerificationSocietePage::getUrl() : MagasinResource::getUrl('edit', ['record' => $magasin]),
                    bouton: 'Voir',
                    aSurveiller: false,
                    date: $magasin->created_at === null ? null : $paris->jourDe($magasin->created_at),
                );
            }

            $adresse = array_values(array_diff($magasin->mentionsLegalesManquantes(), $magasin->societe->mentionsLegalesManquantes()));

            if ($adresse !== []) {
                $choses[] = new ChoseAFaire(
                    cle: 'adresse_du_magasin',
                    groupe: 'Magasins',
                    urgence: UrgenceAFaire::Orange,
                    nombre: 1,
                    phrase: "Pour facturer un professionnel depuis {$magasin->nom}, il manque : ".implode(', ', $adresse).'.',
                    url: MagasinResource::getUrl('edit', ['record' => $magasin]),
                    bouton: 'Compléter',
                    aSurveiller: false,
                );
            }
        }

        return $choses;
    }

    /**
     * Les écarts relevés au répertoire Sirene après la validation que ce
     * compte peut dire vérifiés (voir VerifierUnEcartSireneAction), du
     * plus ancien au plus récent.
     *
     * @return list<ChoseAFaire>
     */
    private function ecartsSirene(User $compte, Societe $societe, Calendrier $paris): array
    {
        return array_values(EcartSirene::query()
            ->where('societe_id', $societe->getKey())
            ->aVerifier()
            ->with('magasin')
            ->orderBy('signale_le')
            ->orderBy('id')
            ->get()
            ->filter(fn (EcartSirene $ecart): bool => app(PorteeDesDroits::class)->peutVerifierLEcart($compte, $ecart))
            ->map(fn (EcartSirene $ecart): ChoseAFaire => new ChoseAFaire(
                cle: 'ecart_sirene',
                groupe: 'Répertoire Sirene',
                urgence: $ecart->negatif ? UrgenceAFaire::Rouge : UrgenceAFaire::Orange,
                nombre: 1,
                phrase: 'Écart relevé au répertoire Sirene : '.$ecart->motif,
                url: null,
                bouton: 'C\'est vérifié',
                aSurveiller: false,
                date: $ecart->signale_le === null ? null : $paris->jourDe($ecart->signale_le),
                precision: 'Rien n\'est bloqué : vérifiez l\'information, contactez l\'éditeur si besoin, puis dites « C\'est vérifié ». Relevé le '.($ecart->signale_le === null ? '' : $paris->afficher($ecart->signale_le, 'd/m/Y')).'.',
                cible: $ecart->id,
            ))
            ->all());
    }

    /**
     * Les identifiants de ceux de ces magasins où ce compte a toutes ces
     * autorisations, dans leur ordre.
     *
     * @param  EloquentCollection<array-key, Magasin>  $magasins
     * @return list<int>
     */
    private function ou(EloquentCollection $magasins, User $compte, string ...$autorisations): array
    {
        $ids = [];

        foreach ($magasins as $magasin) {
            if (array_diff($autorisations, $compte->autorisationsDans($magasin)) === []) {
                $ids[] = $magasin->id;
            }
        }

        return $ids;
    }

    /**
     * Le nombre de fiches que retient cette requête, par magasin, parmi ces
     * magasins : une seule requête pour tous, sans MagasinScope, filtrée
     * explicitement par leurs identifiants, qui ne compte que des lignes
     * (jamais une somme de montants). Un magasin sans fiche n'y figure pas.
     *
     * @template TModele of Model
     *
     * @param  Builder<TModele>  $requete
     * @param  list<int>  $magasins
     * @return array<int, int>
     */
    private function compter(Builder $requete, array $magasins): array
    {
        if ($magasins === []) {
            return [];
        }

        $colonne = $requete->qualifyColumn('magasin_id');

        return $requete->withoutGlobalScope(MagasinScope::class)
            ->whereIn($colonne, $magasins)
            ->toBase()
            ->select($colonne)
            ->selectRaw('count(*) as nombre')
            ->groupBy($colonne)
            ->pluck('nombre', 'magasin_id')
            ->mapWithKeys(fn (mixed $nombre, mixed $magasin): array => [ValeursLues::entier($magasin) => ValeursLues::entier($nombre)])
            ->all();
    }

    /**
     * Le nombre de devis envoyés qui expirent dans les 7 jours, par
     * magasin (voir Devis::scopeExpirentBientot()), chacun compté au jour
     * de son magasin, dans son calendrier : deux magasins d'une même
     * société peuvent ne pas être le même jour. Une seule requête pour
     * tous, une condition par jour.
     *
     * @param  EloquentCollection<array-key, Magasin>  $magasins
     * @return array<int, int>
     */
    private function devisQuiExpirent(EloquentCollection $magasins): array
    {
        $parJour = $magasins->groupBy(fn (Magasin $magasin): string => $magasin->calendrier()->aujourdhui());

        return $this->compter(Devis::query()->where(function (Builder $jours) use ($parJour): void {
            foreach ($parJour as $jour => $siens) {
                $jours->orWhere(fn (Builder $ceJour): Builder => $ceJour->whereIn('devis.magasin_id', $siens->modelKeys())->expirentBientot((string) $jour));
            }
        }), array_values($magasins->map(fn (Magasin $magasin): int => $magasin->id)->all()));
    }

    /**
     * Le crédit qui reste de chaque avoir émis encore en partie ou en
     * entier inutilisé, en centimes, par magasin, parmi ces magasins (voir
     * Avoir::creditApres()) : une requête pour les avoirs, une pour leurs
     * imputations, sans MagasinScope, filtrées explicitement par leurs
     * identifiants.
     *
     * @param  list<int>  $magasins
     * @return array<int, list<int>>
     */
    private function avoirsNonUtilises(array $magasins): array
    {
        if ($magasins === []) {
            return [];
        }

        $restants = [];

        Avoir::withoutGlobalScope(MagasinScope::class)
            ->whereIn('avoirs.magasin_id', $magasins)
            ->where('statut', StatutAvoir::Emise)
            ->with(['allocations' => fn (HasMany $imputations): HasMany => $imputations
                ->withoutGlobalScope(MagasinScope::class)
                ->whereIn($imputations->qualifyColumn('magasin_id'), $magasins)
                ->select(['id', 'avoir_id', 'montant_alloue'])])
            ->orderBy('id')
            ->get(['id', 'magasin_id', 'total_ttc'])
            ->each(function (Avoir $avoir) use (&$restants): void {
                $credit = $avoir->creditApres($avoir->allocations->map(fn (AvoirAllocation $allocation): string => (string) $allocation->montant_alloue));

                if ($credit > 0) {
                    $restants[$avoir->magasin_id][] = $credit;
                }
            });

        return $restants;
    }
}
