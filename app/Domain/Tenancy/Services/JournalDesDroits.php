<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Authorization\LecteurDesDroits;
use App\Domain\Authorization\OperateurCourant;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Models\Cabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\AccesDesCabinets;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Toute modification des droits d'une personne passe par modifier(), qui
 * l'inscrit au journal des événements de chaque magasin qu'elle touche
 * (JET 130), avec ses droits avant et après dans ce magasin, relus en base
 * (voir User::droitsDans()) : son rôle de société, son rôle de magasin,
 * son statut et ses autorisations, ou null quand elle n'y en a aucun (avant
 * sa création ou son affectation, après son retrait) ; puis au journal
 * d'audit de la société, en une entrée qui donne, pour chaque compte
 * touché, ses droits avant et après. Le propriétaire et un administrateur
 * de tous les magasins touchent tous les magasins de la société ; un
 * compte d'un cabinet d'expertise comptable, les magasins réels des
 * clients de son cabinet qu'il voit (voir
 * AccesDesCabinets::magasinsDuCompte()) ; tout autre compte, ceux de ses
 * affectations, avant comme après.
 *
 * Une modification se fait dans une transaction qui verrouille d'abord les
 * registres de ces magasins, par identifiant croissant (voir
 * RegistreFiscal::sousVerrouDesMagasins(), et, pour les magasins des
 * clients d'un cabinet, RegistreFiscal::sousVerrouDesMagasinsDesClients()),
 * puis les lignes de l'auteur et des comptes visés, par identifiant
 * croissant : deux modifications des
 * droits d'une même personne se suivent, et une vente qui relit sous le
 * verrou de son registre puis de la ligne de son auteur l'autorisation de
 * celui-ci (voir LecteurDesDroits) passe tout entière avant ou après. La
 * modification reçoit l'auteur et les comptes relus sous ces verrous :
 * c'est sur eux qu'elle applique les règles de PorteeDesDroits, jamais sur
 * des instances dont les droits seraient périmés (un responsable retiré de
 * son magasin, un administrateur privé de son autorisation, un auteur
 * suspendu pendant que son formulaire restait ouvert). Elle écrit au nom
 * de l'auteur relu (voir OperateurCourant). Si la liste des magasins
 * touchés a changé entre sa lecture et ces verrous, tout reprend avec la
 * nouvelle liste. Fermé par défaut : un registre qui ne peut pas inscrire
 * refuse toute la modification. Une seule modification se fait sans
 * auteur, au nom du système : le retrait des comptes d'entraînement quand
 * la démonstration se ferme pour de bon (voir FermerLaDemonstrationAction).
 *
 * L'accès des comptes d'un cabinet chez un client passe par
 * modifierLeLien(), dans le contexte du client : son journal d'audit et
 * celui de chaque cabinet du lien reçoivent leurs entrées, chacune avec
 * les droits des seuls comptes de ce cabinet.
 */
class JournalDesDroits
{
    private const ESSAIS_SI_LES_MAGASINS_CHANGENT = 3;

    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly JournalAudit $journalAudit,
        private readonly OperateurCourant $operateur,
        private readonly AccesDesCabinets $acces,
        private readonly TraceDuVisiteur $traces,
    ) {}

    /**
     * Exécute la modification des droits de ces comptes, sous les verrous
     * des registres des magasins qu'ils touchent (ceux de leurs droits
     * actuels et ceux qu'elle vise), puis des lignes de l'auteur et des
     * comptes, et inscrit au JET 130 de chacun de ces magasins ce qui y a
     * changé pour chacun d'eux, puis l'entrée d'audit. La modification
     * reçoit l'auteur relu (null pour le système) et les comptes relus,
     * dans l'ordre donné ; un compte qu'elle crée, elle le rend (seul, ou
     * dans un tableau avec ses autres résultats sous la clé « compte »).
     *
     * @template TResultat
     *
     * @param  User|null  $auteur  null pour le système
     * @param  list<User>  $comptes  les comptes existants dont les droits changent
     * @param  iterable<Magasin>  $magasinsVises  les magasins que leurs nouveaux droits toucheront
     * @param  Closure(User|null, list<User>): TResultat  $modification
     * @param  (Closure(): void)|null  $verrousAvantLesComptes  d'autres verrous, pris juste après les registres et avant les comptes (les liens des clients confiés, voir ConfierDesClientsAction)
     * @return TResultat
     *
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function modifier(?User $auteur, ActionAuditee $action, array $comptes, iterable $magasinsVises, Closure $modification, ?Closure $verrousAvantLesComptes = null): mixed
    {
        $vises = collect($magasinsVises)->map(fn (Magasin $magasin): int => (int) $magasin->getKey())->all();
        $ids = array_map(fn (User $compte): int => (int) $compte->getKey(), $comptes);

        for ($essai = 1; $essai <= self::ESSAIS_SI_LES_MAGASINS_CHANGENT; $essai++) {
            $magasins = $this->magasinsTouches($ids, $vises);
            $termine = false;

            $resultat = $this->sousVerrouDesRegistres($magasins, function () use ($auteur, $action, $ids, $vises, $magasins, $modification, $verrousAvantLesComptes, &$termine): mixed {
                if ($verrousAvantLesComptes !== null) {
                    $verrousAvantLesComptes();
                }

                $verrouilles = User::query()->whereKey($auteur === null ? $ids : [...$ids, (int) $auteur->getKey()])->orderBy('id')->lockForUpdate()->get()->keyBy(fn (User $compte): int => (int) $compte->getKey());
                $auteurRelu = $auteur === null ? null : ($verrouilles->get((int) $auteur->getKey()) ?? throw new LogicException('Ce compte n\'existe pas.'));
                $relus = array_map(fn (int $id): User => $verrouilles->get($id) ?? throw new LogicException('Ce compte n\'existe pas.'), $ids);
                $touches = array_map(intval(...), $this->magasinsTouches($ids, $vises)->modelKeys());

                if (array_diff($touches, array_map(intval(...), $magasins->modelKeys())) !== []) {
                    return null;
                }

                $auNomDe = fn (Closure $operation): mixed => $auteurRelu === null ? $this->operateur->pendantLeSysteme($operation) : $this->operateur->pendant($auteurRelu, $operation);

                $resultat = $auNomDe(function () use ($auteurRelu, $action, $relus, $magasins, $modification): mixed {
                    $avant = $this->etats($magasins, $relus);
                    $resultat = $modification($auteurRelu, $relus);
                    $cree = $resultat instanceof User ? $resultat : (is_array($resultat) && ($resultat['compte'] ?? null) instanceof User ? $resultat['compte'] : null);
                    $apres = $this->etats($magasins, $cree === null ? $relus : [...$relus, $cree]);

                    $this->inscrire($auteurRelu, $action, $magasins, $avant, $apres);

                    return $resultat;
                });

                $termine = true;

                return $resultat;
            });

            if ($termine) {
                foreach ($comptes as $compte) {
                    $compte->oublierLesDroits();
                }

                return $resultat;
            }
        }

        throw new LogicException('Les droits de ce compte changent en ce moment : recommencez.');
    }

    /**
     * Exécute un geste sur des comptes qui ne change pas leurs droits (un
     * accès redonné, un identifiant affiché), dans une
     * transaction qui verrouille les lignes de l'auteur et de ces comptes,
     * par identifiant croissant, comme modifier() : le geste reçoit
     * l'auteur et les comptes relus sous ce verrou, et s'inscrit au nom de
     * l'auteur relu.
     *
     * @template TResultat
     *
     * @param  list<User>  $comptes
     * @param  Closure(User, list<User>): TResultat  $geste
     * @return TResultat
     *
     * @throws LogicException
     */
    public function sousVerrouDesComptes(User $auteur, array $comptes, Closure $geste): mixed
    {
        $ids = array_map(fn (User $compte): int => (int) $compte->getKey(), $comptes);

        return DB::transaction(function () use ($auteur, $ids, $geste): mixed {
            $verrouilles = User::query()->whereKey([...$ids, (int) $auteur->getKey()])->orderBy('id')->lockForUpdate()->get()->keyBy(fn (User $compte): int => (int) $compte->getKey());
            $auteurRelu = $verrouilles->get((int) $auteur->getKey()) ?? throw new LogicException('Ce compte n\'existe pas.');
            $relus = array_map(fn (int $id): User => $verrouilles->get($id) ?? throw new LogicException('Ce compte n\'existe pas.'), $ids);

            return $this->operateur->pendant($auteurRelu, fn (): mixed => $geste($auteurRelu, $relus));
        });
    }

    /**
     * Exécute un changement de l'accès des comptes d'un cabinet chez ce
     * client (un lien activé, remplacé, terminé, ses autorisations
     * réglées), dans le contexte du client, sous les verrous, dans l'ordre :
     * les registres des magasins réels du client (voir
     * RegistreFiscal::sousVerrouDesMagasins()), la ligne du client (« for
     * no key update », comme la clôture d'une inscription qui la tient déjà
     * quand elle met fin au lien : ce verrou sérialise les gestes sur les
     * liens d'un client sans retenir une insertion qui la référence), les
     * liens (ceux donnés, et le lien actif du client), la vérification de
     * leurs cabinets et de ceux qui entrent (partagée), puis les comptes :
     * l'auteur, et les comptes des cabinets qui ont accès par ces liens ou
     * y entrent. Si la liste des magasins a changé entre sa lecture et ces
     * verrous, tout reprend. La modification reçoit l'auteur relu (null
     * pour le système ou l'éditeur), qu'elle contrôle elle-même dans sa
     * propre société (voir exigerDansSaSociete()), le client verrouillé et
     * les liens relus, dans l'ordre donné ; elle écrit au nom de l'auteur
     * relu. Ensuite, les droits de chaque compte qui avait ou a désormais
     * accès s'inscrivent au JET 130 de chaque magasin réel du client où ils
     * ont changé, puis les entrées d'audit au journal du client et au
     * journal de chaque cabinet de ces liens : $entrees donne, pour chaque
     * journal, ses entrées (l'action, ses autres valeurs, et le cabinet
     * dont elle porte les droits des comptes) ; au journal d'un cabinet,
     * une entrée ne porte que les droits de ses comptes : un cabinet ne lit
     * jamais l'identité ni les comptes d'un autre cabinet.
     *
     * @template TResultat
     *
     * @param  list<LienCabinet>  $liens
     * @param  Closure(User|null, Societe, list<LienCabinet>): TResultat  $modification
     * @param  Closure(Societe, list<LienCabinet>): list<array{0: ActionAuditee, 1: array<string, mixed>, 2: int}>  $entrees
     * @param  list<int>  $cabinetsQuiEntrent  les cabinets d'un lien que la modification crée
     * @return TResultat
     *
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function modifierLeLien(?User $auteur, Societe $client, array $liens, Closure $modification, Closure $entrees, array $cabinetsQuiEntrent = [], ?InterventionDeLEditeur $editeur = null): mixed
    {
        return app(TenantContext::class)->runAsSociete($client, function () use ($auteur, $client, $liens, $modification, $entrees, $cabinetsQuiEntrent, $editeur): mixed {
            for ($essai = 1; $essai <= self::ESSAIS_SI_LES_MAGASINS_CHANGENT; $essai++) {
                $magasins = $client->magasinsReels()->orderBy('id')->get();
                $termine = false;

                $resultat = $this->registre->sousVerrouDesMagasins($magasins, function () use ($auteur, $client, $liens, $modification, $entrees, $cabinetsQuiEntrent, $editeur, $magasins, &$termine): mixed {
                    $clientVerrouille = Societe::query()->whereKey($client->getKey())->lock('for no key update')->firstOrFail();

                    if (array_map(intval(...), $clientVerrouille->magasinsReels()->orderBy('id')->pluck('id')->all()) !== array_map(intval(...), $magasins->modelKeys())) {
                        return null;
                    }

                    $ids = array_map(fn (LienCabinet $lien): int => (int) $lien->getKey(), $liens);
                    $actif = LienCabinet::query()->where('etat', EtatDuLien::Actif->value)->value('id');
                    $verrouilles = LienCabinet::query()->whereKey(array_values(array_unique([...$ids, ...($actif === null ? [] : [(int) $actif])])))->orderBy('id')->lockForUpdate()->get()->keyBy(fn (LienCabinet $lien): int => (int) $lien->getKey());
                    $relus = array_map(fn (int $id): LienCabinet => $verrouilles->get($id) ?? throw new LogicException('Ce lien n\'existe pas.'), $ids);
                    $cabinets = array_values(array_unique([...$verrouilles->map(fn (LienCabinet $lien): int => (int) $lien->cabinet_id)->all(), ...$cabinetsQuiEntrent]));
                    sort($cabinets);
                    Cabinet::query()->withoutGlobalScope(SocieteScope::class)->whereIn('societe_id', $cabinets)->orderBy('id')->sharedLock()->get(['id']);
                    $comptesAvant = $verrouilles->flatMap(fn (LienCabinet $lien) => $this->acces->comptesQuiOntAcces($lien))->keyBy(fn (User $compte): int => (int) $compte->getKey());
                    $aVerrouiller = [
                        ...$comptesAvant->keys()->all(),
                        ...User::query()->whereIn('societe_id', $cabinets)->where('role_societe', RoleSociete::Proprietaire->value)->pluck('id')->map(intval(...))->all(),
                        ...($auteur === null ? [] : [(int) $auteur->getKey()]),
                    ];
                    $comptesVerrouilles = User::query()->whereKey(array_values(array_unique($aVerrouiller)))->orderBy('id')->lockForUpdate()->get()->keyBy(fn (User $compte): int => (int) $compte->getKey());
                    $auteurRelu = $auteur === null ? null : ($comptesVerrouilles->get((int) $auteur->getKey()) ?? throw new LogicException('Ce compte n\'existe pas.'));
                    $auNomDe = fn (Closure $operation): mixed => $auteurRelu === null ? $this->operateur->pendantLeSysteme($operation) : $this->operateur->pendant($auteurRelu, $operation);

                    $resultat = $auNomDe(function () use ($auteurRelu, $clientVerrouille, $relus, $verrouilles, $modification, $entrees, $editeur, $magasins, $comptesAvant): mixed {
                        $avant = $this->etats($magasins, $comptesAvant->values()->all());
                        $resultat = $modification($auteurRelu, $clientVerrouille, $relus);
                        $liensApres = LienCabinet::query()
                            ->where(fn ($apres) => $apres->whereKey($verrouilles->keys()->all())->orWhere('etat', EtatDuLien::Actif->value))
                            ->orderBy('id')
                            ->get();
                        $comptesApres = $liensApres->flatMap(fn (LienCabinet $lien) => $this->acces->comptesQuiOntAcces($lien))->keyBy(fn (User $compte): int => (int) $compte->getKey());
                        $apres = $this->etats($magasins, $comptesAvant->union($comptesApres)->sortKeys()->values()->all());

                        foreach ($apres as $compteId => $etat) {
                            $this->inscrireLeJet130($magasins, $etat['compte'], $avant[$compteId]['magasins'] ?? [], $etat['magasins']);
                        }

                        $concernes = $relus !== [] ? array_map(fn (LienCabinet $lien): LienCabinet => $liensApres->firstWhere('id', $lien->getKey()) ?? $lien, $relus) : $liensApres->filter(fn (LienCabinet $lien): bool => ! $verrouilles->has((int) $lien->getKey()))->values()->all();
                        $this->inscrireAuxJournauxDuLien($auteurRelu, $clientVerrouille, $liensApres, $concernes, $avant, $apres, $entrees, $editeur);

                        return $resultat;
                    });

                    $termine = true;

                    return $resultat;
                });

                if ($termine) {
                    return $resultat;
                }
            }

            throw new LogicException('Les droits de ce cabinet changent en ce moment : recommencez.');
        });
    }

    /**
     * Contrôle l'auteur d'un geste sur un lien dans sa propre société (le
     * client ou le cabinet), quel que soit le contexte où le geste
     * s'écrit : dans le contexte d'un client, un compte d'un cabinet n'a
     * aucun droit (voir ControleDesDroits), et un geste de la société ne se
     * contrôle que dans le contexte de la société de son auteur. Rend le
     * compte relu par le contrôle.
     *
     * @throws AuthorizationException
     */
    public function exigerDansSaSociete(User $auteur, string $geste, mixed $cible): User
    {
        $societe = Societe::query()->findOrFail($auteur->societe_id);

        return app(TenantContext::class)->runAsSociete($societe, fn (): User => app(LecteurDesDroits::class)->exigerDansLaSociete($auteur, $geste, $societe, $cible));
    }

    /**
     * Inscrit, au JET 130 de ce nouveau magasin, les droits de chaque
     * personne qui y en a sans affectation (le propriétaire de la société,
     * ses administrateurs de tous les magasins, et, pour un magasin réel,
     * les comptes du cabinet lié qui ont accès à la société), avant nul :
     * le magasin doit être le magasin actif, dans la transaction de
     * l'appelant (voir MiseEnActiviteDesMagasins et
     * OuvrirLaDemonstrationAction).
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function inscrireLesDroitsImplicites(Magasin $magasin): void
    {
        $this->registre->verrouiller($magasin);

        $comptes = User::query()
            ->where('societe_id', $magasin->societe_id)
            ->where('statut', '<>', StatutCompte::Retire->value)
            ->where(fn ($comptes) => $comptes
                ->where('role_societe', RoleSociete::Proprietaire->value)
                ->orWhereIn('id', Affectation::query()->where('societe_id', $magasin->societe_id)->whereNull('magasin_id')->select('user_id')))
            ->orderBy('id')
            ->get();

        $lien = $magasin->est_demonstration ? null : LienCabinet::query()->withoutGlobalScope(SocieteScope::class)->where('societe_id', $magasin->societe_id)->where('etat', EtatDuLien::Actif->value)->first();
        $duCabinet = $lien === null ? new Collection : $this->acces->comptesQuiOntAcces($lien);

        foreach ($comptes->concat($duCabinet) as $compte) {
            $this->inscrireLeJet130(new Collection([$magasin]), $compte, [], [$magasin->getKey() => $compte->droitsDans($magasin)]);
        }
    }

    /**
     * Les magasins que touchent les droits actuels de ces comptes, relus en
     * base, et ceux que visent leurs nouveaux droits, par identifiant : pour
     * les comptes d'un cabinet d'expertise comptable, les magasins réels des
     * clients qui leur sont ouverts, et ceux des clients qu'on leur confie.
     *
     * @param  list<int>  $comptes
     * @param  list<int>  $vises
     * @return Collection<int, Magasin>
     */
    private function magasinsTouches(array $comptes, array $vises): Collection
    {
        $societe = app(TenantContext::class)->societe();

        if ($societe->estUnCabinet()) {
            $touches = User::query()->whereKey($comptes)->where('societe_id', $societe->getKey())->orderBy('id')->get()
                ->flatMap(fn (User $compte) => $this->acces->magasinsDuCompte($compte))
                ->concat(Magasin::query()->whereKey($vises)->where('est_demonstration', false)->get());

            return new Collection($touches->unique(fn (Magasin $magasin): int => (int) $magasin->getKey())->sortBy(fn (Magasin $magasin): int => (int) $magasin->getKey())->values()->all());
        }

        $societeId = $societe->getKey();
        $tous = User::query()->whereKey($comptes)->where('societe_id', $societeId)->where(fn ($touchant) => $touchant
            ->where('role_societe', RoleSociete::Proprietaire->value)
            ->orWhereIn('id', Affectation::query()->where('societe_id', $societeId)->whereNull('magasin_id')->select('user_id')))->exists();

        $magasins = Magasin::query()->where('societe_id', $societeId);

        if (! $tous) {
            $magasins->where(fn ($touches) => $touches
                ->whereIn('id', Affectation::query()->where('societe_id', $societeId)->whereIn('user_id', $comptes)->whereNotNull('magasin_id')->select('magasin_id'))
                ->orWhereIn('id', $vises));
        }

        return $magasins->orderBy('id')->get();
    }

    /**
     * L'état des droits de chacun de ces comptes, relu en base : son nom,
     * son rôle de société, son statut, ses autorisations de société et ses
     * droits dans chacun de ces magasins.
     *
     * @param  Collection<int, Magasin>  $magasins
     * @param  list<User>  $comptes
     * @return array<int, array{compte: User, societe: array<string, mixed>, magasins: array<int, array<string, mixed>|null>}>
     */
    private function etats(Collection $magasins, array $comptes): array
    {
        $etats = [];

        foreach ($comptes as $compte) {
            $relu = User::query()->findOrFail($compte->getKey());
            $etats[$relu->getKey()] = [
                'compte' => $relu,
                'societe' => [
                    'role_societe' => $relu->role_societe->value,
                    'statut' => $relu->statut->value,
                    'autorisations_societe' => $relu->autorisationsDeSociete(),
                ],
                'magasins' => $magasins->mapWithKeys(fn (Magasin $magasin): array => [$magasin->getKey() => $relu->droitsDans($magasin)])->all(),
            ];
        }

        return $etats;
    }

    /**
     * Inscrit au JET 130 de chaque magasin ce qui y a changé pour chaque
     * compte, puis l'entrée d'audit de ce qui a changé : une modification
     * qui ne change rien n'en est pas une.
     *
     * @param  Collection<int, Magasin>  $magasins
     * @param  array<int, array{compte: User, societe: array<string, mixed>, magasins: array<int, array<string, mixed>|null>}>  $avant
     * @param  array<int, array{compte: User, societe: array<string, mixed>, magasins: array<int, array<string, mixed>|null>}>  $apres
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    private function inscrire(?User $auteur, ActionAuditee $action, Collection $magasins, array $avant, array $apres): void
    {
        $droitsAvant = [];
        $droitsApres = [];

        foreach ($apres as $compteId => $etat) {
            $etatAvant = $avant[$compteId] ?? null;
            $this->inscrireLeJet130($magasins, $etat['compte'], $etatAvant['magasins'] ?? [], $etat['magasins']);

            $resumeAvant = $etatAvant === null ? null : self::resume($etatAvant);
            $resumeApres = self::resume($etat);

            if ($resumeAvant !== $resumeApres) {
                $droitsAvant[$compteId] = $resumeAvant;
                $droitsApres[$compteId] = $resumeApres;
            }
        }

        if ($droitsApres !== []) {
            $this->journalAudit->inscrire($action, $auteur, null, ['droits' => $droitsAvant], ['droits' => $droitsApres]);
        }
    }

    /**
     * Exécute $operation sous le verrou des registres de ces magasins : ceux
     * de la société active, ou, dans le contexte d'un cabinet d'expertise
     * comptable, ceux des magasins de ses clients (voir
     * RegistreFiscal::sousVerrouDesMagasinsDesClients()).
     *
     * @template TResultat
     *
     * @param  Collection<int, Magasin>  $magasins
     * @param  Closure(): TResultat  $operation
     * @return TResultat
     */
    private function sousVerrouDesRegistres(Collection $magasins, Closure $operation): mixed
    {
        $societe = app(TenantContext::class)->societe();

        return $societe->estUnCabinet() && $magasins->isNotEmpty()
            ? $this->registre->sousVerrouDesMagasinsDesClients($societe, $magasins, $operation)
            : $this->registre->sousVerrouDesMagasins($magasins, $operation);
    }

    /**
     * Les entrées d'audit d'un changement de lien, au journal du client
     * puis au journal de chaque cabinet de ces liens, chacune avec les
     * droits des comptes du cabinet qu'elle concerne ; au journal d'un
     * cabinet, l'auteur n'est nommé que s'il est de ce cabinet ou du
     * client : le propriétaire du cabinet qui en remplace un autre n'y
     * figure pas. La trace du visiteur ne se note qu'au journal de la
     * société de l'auteur, du client pour le système ou l'éditeur (voir
     * TraceDuVisiteur) : le propriétaire d'un client ne laisse pas la sienne
     * au journal de son cabinet, ni l'inverse.
     *
     * @param  Collection<int, LienCabinet>  $liens
     * @param  list<LienCabinet>  $concernes  les liens dont parlent les entrées, la première étant leur cible
     * @param  array<int, array{compte: User, societe: array<string, mixed>, magasins: array<int, array<string, mixed>|null>}>  $avant
     * @param  array<int, array{compte: User, societe: array<string, mixed>, magasins: array<int, array<string, mixed>|null>}>  $apres
     */
    private function inscrireAuxJournauxDuLien(?User $auteur, Societe $client, Collection $liens, array $concernes, array $avant, array $apres, Closure $entrees, ?InterventionDeLEditeur $editeur): void
    {
        $cabinets = $liens->map(fn (LienCabinet $lien): int => (int) $lien->cabinet_id)->unique()->sort()->values();
        $journaux = [$client, ...$cabinets->map(fn (int $id): Societe => Societe::query()->findOrFail($id))->all()];
        $duVisiteur = (int) ($auteur?->societe_id ?? $client->getKey());

        foreach ($journaux as $journal) {
            foreach ($entrees($journal, $concernes) as [$action, $valeurs, $cabinetId]) {
                $droitsAvant = [];
                $droitsApres = [];

                foreach ($apres as $compteId => $etat) {
                    if ((int) $etat['compte']->societe_id !== (int) $cabinetId) {
                        continue;
                    }

                    $resumeAvant = isset($avant[$compteId]) ? self::resume($avant[$compteId]) : null;
                    $resumeApres = self::resume($etat);

                    if ($resumeAvant !== $resumeApres) {
                        $droitsAvant[$compteId] = $resumeAvant;
                        $droitsApres[$compteId] = $resumeApres;
                    }
                }

                $inscrire = fn () => app(TenantContext::class)->runAsSociete($journal, fn () => $this->journalAudit->inscrire(
                    $action,
                    $journal->is($client) || ($auteur !== null && in_array((int) $auteur->societe_id, [(int) $journal->getKey(), (int) $client->getKey()], true)) ? $auteur : null,
                    null,
                    ['droits' => $droitsAvant],
                    ['droits' => $droitsApres, ...$valeurs],
                    $concernes[0] ?? null,
                    $editeur,
                ));
                (int) $journal->getKey() === $duVisiteur ? $inscrire() : $this->traces->horsDeSaSociete($inscrire);
            }
        }
    }

    /**
     * Ce que l'audit retient de l'état d'un compte : son nom au moment de
     * la modification, son rôle de société, son statut, ses autorisations
     * de société et ses droits dans les magasins où il en a.
     *
     * @param  array{compte: User, societe: array<string, mixed>, magasins: array<int, array<string, mixed>|null>}  $etat
     * @return array<string, mixed>
     */
    private static function resume(array $etat): array
    {
        return [
            'nom' => $etat['compte']->nomPourLHistorique(),
            ...$etat['societe'],
            'magasins' => array_filter($etat['magasins'], static fn (?array $droits): bool => $droits !== null),
        ];
    }

    /**
     * Inscrit au JET 130 de chaque magasin ce qui y a changé pour ce compte,
     * chacun dans le contexte de sa société (celle d'un client, pour un
     * compte d'un cabinet d'expertise comptable). L'opérateur d'une autre
     * société que celle du magasin (le propriétaire d'un cabinet qui confie
     * un client ou accepte un lien depuis l'espace du cabinet) n'y laisse
     * pas sa trace (voir TraceDuVisiteur).
     *
     * @param  Collection<int, Magasin>  $magasins
     * @param  array<int, array<string, mixed>|null>  $avant
     * @param  array<int, array<string, mixed>|null>  $apres
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    private function inscrireLeJet130(Collection $magasins, User $compte, array $avant, array $apres): void
    {
        foreach ($magasins as $magasin) {
            $droitsAvant = $avant[$magasin->getKey()] ?? null;
            $droitsApres = $apres[$magasin->getKey()] ?? null;

            if ($droitsAvant === $droitsApres) {
                continue;
            }

            $journaliser = fn () => app(TenantContext::class)->runAs($magasin, fn () => $this->registre->journaliser(CodeEvenement::ModificationDesDroits, [
                'membre' => ['id' => $compte->getKey(), 'nom' => $compte->nomPourLHistorique()],
                'avant' => $droitsAvant,
                'apres' => $droitsApres,
            ]));

            $dansSaSociete = fn () => (int) $magasin->societe_id === (int) app(TenantContext::class)->societe()->getKey()
                ? $journaliser()
                : app(TenantContext::class)->runAsSociete($magasin->societe, $journaliser);
            $operateur = $this->operateur->actuel();
            $operateur === null || (int) $operateur->societe_id === (int) $magasin->societe_id ? $dansSaSociete() : $this->traces->horsDeSaSociete($dansSaSociete);
        }
    }
}
