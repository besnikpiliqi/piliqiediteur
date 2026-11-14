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
use App\Domain\Tenancy\Support\CleDuModele;
use App\Domain\Tenancy\Support\InterventionDeLEditeur;
use App\Domain\Tenancy\Support\ValeursLues;
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
 * AccesDesCabinets::magasinsDuCompte()) ; un expert-comptable de la
 * société, les magasins réels de sa société, jamais la démonstration ;
 * tout autre compte, ceux de ses affectations, avant comme après.
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
 * refuse toute la modification. Les modifications sans auteur sont
 * celles du système ou de l'éditeur : le retrait des comptes
 * d'entraînement quand la démonstration se ferme pour de bon (voir
 * FermerLaDemonstrationAction), la reprise de la société à la saisie du
 * code par une autre personne, la fin d'un lien ou d'une déclaration par
 * le système ou par une procédure de l'éditeur, et le rattachement d'une
 * déclaration par un job (voir RattacherUneDeclarationAction).
 *
 * L'accès des comptes d'un cabinet chez un client, comme celui des comptes
 * de l'expert-comptable de la société, passe par modifierLeLien(), dans le
 * contexte du client, sur son lien en cours (le lien actif ou la
 * déclaration) : son journal d'audit et celui de chaque cabinet du lien
 * reçoivent leurs entrées, chacune avec les droits des seuls comptes d'une
 * société (ceux d'un cabinet, ou ceux de l'expert-comptable au seul
 * journal du client).
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
     * @param  iterable<array-key, Magasin>  $magasinsVises  les magasins que leurs nouveaux droits toucheront
     *
     * @param-immediately-invoked-callable  $modification
     *
     * @param  ($auteur is null ? Closure(null, list<User>): TResultat : Closure(User, list<User>): TResultat)  $modification
     * @param  (Closure(): void)|null  $verrousAvantLesComptes  d'autres verrous, pris juste après les registres et avant les comptes (les liens des clients confiés, voir ConfierDesClientsAction)
     * @return TResultat
     *
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function modifier(?User $auteur, ActionAuditee $action, array $comptes, iterable $magasinsVises, Closure $modification, ?Closure $verrousAvantLesComptes = null): mixed
    {
        $vises = array_values(collect($magasinsVises)->map(fn (Magasin $magasin): int => CleDuModele::de($magasin))->all());
        $ids = array_map(fn (User $compte): int => CleDuModele::de($compte), $comptes);

        for ($essai = 1; $essai <= self::ESSAIS_SI_LES_MAGASINS_CHANGENT; $essai++) {
            $magasins = $this->magasinsTouches($ids, $vises);

            // Le résultat de la modification, dans un tableau d'un élément,
            // ou null quand les magasins touchés ont changé entre leur
            // lecture et leurs verrous : tout reprend alors.
            $tenu = $this->sousVerrouDesRegistres($magasins, function () use ($auteur, $action, $ids, $vises, $magasins, $modification, $verrousAvantLesComptes) {
                if ($verrousAvantLesComptes !== null) {
                    $verrousAvantLesComptes();
                }

                $verrouilles = User::query()->with('societe')->whereKey($auteur === null ? $ids : [...$ids, CleDuModele::de($auteur)])->orderBy('id')->lockForUpdate()->get()->keyBy(fn (User $compte): int => CleDuModele::de($compte));
                $auteurRelu = $auteur === null ? null : ($verrouilles->get(CleDuModele::de($auteur)) ?? throw new LogicException('Ce compte n\'existe pas.'));
                $relus = array_map(fn (int $id): User => $verrouilles->get($id) ?? throw new LogicException('Ce compte n\'existe pas.'), $ids);
                $touches = array_map(intval(...), $this->magasinsTouches($ids, $vises)->modelKeys());

                if (array_diff($touches, array_map(intval(...), $magasins->modelKeys())) !== []) {
                    return null;
                }

                return [$this->operateur->auNomDe($auteurRelu, function () use ($auteurRelu, $action, $relus, $magasins, $modification) {
                    $avant = $this->etats($magasins, $relus);
                    $resultat = $modification($auteurRelu, $relus);
                    $cree = self::compteCree($resultat);
                    $apres = $this->etats($magasins, $cree === null ? $relus : [...$relus, $cree]);

                    $this->inscrire($auteurRelu, $action, $magasins, $avant, $apres);

                    return $resultat;
                })];
            });

            if ($tenu !== null) {
                foreach ($comptes as $compte) {
                    $compte->oublierLesDroits();
                }

                return $tenu[0];
            }
        }

        throw new LogicException('Les droits de ce compte changent en ce moment : recommencez.');
    }

    /**
     * Exécute un geste sur des comptes qui ne change pas leurs droits (un
     * accès redonné, un identifiant affiché, une adresse corrigée), dans
     * une transaction qui verrouille les lignes de l'auteur et de ces
     * comptes, par identifiant croissant, comme modifier() : le geste
     * reçoit l'auteur et les comptes relus sous ce verrou, et s'inscrit au
     * nom de l'auteur relu. $verrousAvantLesComptes prend d'abord, dans la
     * même transaction, les verrous que l'ordre de l'espace des cabinets
     * place avant les comptes (la ligne de la société, puis la déclaration
     * d'un expert-comptable de la société, voir
     * ExpertsComptablesDeclares::verrouillerLaDeclarationDe()).
     *
     * @template TResultat
     *
     * @param  list<User>  $comptes
     * @param  Closure(User, list<User>): TResultat  $geste
     * @param  (Closure(): void)|null  $verrousAvantLesComptes
     * @return TResultat
     *
     * @throws LogicException
     */
    public function sousVerrouDesComptes(User $auteur, array $comptes, Closure $geste, ?Closure $verrousAvantLesComptes = null): mixed
    {
        $ids = array_map(fn (User $compte): int => CleDuModele::de($compte), $comptes);

        return DB::transaction(function () use ($auteur, $ids, $geste, $verrousAvantLesComptes): mixed {
            if ($verrousAvantLesComptes !== null) {
                $verrousAvantLesComptes();
            }

            $verrouilles = User::query()->with('societe')->whereKey([...$ids, CleDuModele::de($auteur)])->orderBy('id')->lockForUpdate()->get()->keyBy(fn (User $compte): int => CleDuModele::de($compte));
            $auteurRelu = $verrouilles->get(CleDuModele::de($auteur)) ?? throw new LogicException('Ce compte n\'existe pas.');
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
     * jamais l'identité ni les comptes d'un autre cabinet. Une procédure de
     * l'éditeur ($editeur) ne laisse sa justification et ses opérateurs
     * qu'au journal de la société qu'elle vise ($visee) : au journal de
     * l'autre partie, l'entrée dit seulement que l'éditeur l'a faite.
     *
     * @template TResultat
     *
     * @param  list<LienCabinet>  $liens
     *
     * @param-immediately-invoked-callable  $modification
     *
     * @param  Closure(User|null, Societe, list<LienCabinet>): TResultat  $modification
     * @param  Closure(Societe, list<LienCabinet>, TResultat): list<array{0: ActionAuditee, 1: array<string, mixed>, 2: int|null}>  $entrees  qui reçoit aussi ce que la modification a rendu ; le troisième élément est la société dont l'entrée porte les droits des comptes (le cabinet, ou le client pour ses comptes d'expert-comptable), nul pour une entrée sans droits
     * @param  list<int>  $cabinetsQuiEntrent  les cabinets d'un lien que la modification crée
     * @param  Societe|null  $visee  la société que vise la procédure de l'éditeur, le client ou le cabinet
     * @return TResultat
     *
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function modifierLeLien(?User $auteur, Societe $client, array $liens, Closure $modification, Closure $entrees, array $cabinetsQuiEntrent = [], ?InterventionDeLEditeur $editeur = null, ?Societe $visee = null): mixed
    {
        return app(TenantContext::class)->runAsSociete($client, function () use ($auteur, $client, $liens, $modification, $entrees, $cabinetsQuiEntrent, $editeur, $visee): mixed {
            for ($essai = 1; $essai <= self::ESSAIS_SI_LES_MAGASINS_CHANGENT; $essai++) {
                $magasins = $client->magasinsReels()->orderBy('id')->get();

                // Le résultat de la modification, dans un tableau d'un élément,
                // ou null quand la liste des magasins a changé entre sa
                // lecture et ces verrous : tout reprend alors.
                $tenu = $this->registre->sousVerrouDesMagasins($magasins, function () use ($auteur, $client, $liens, $modification, $entrees, $cabinetsQuiEntrent, $editeur, $visee, $magasins) {
                    $clientVerrouille = Societe::query()->whereKey($client->getKey())->lock('for no key update')->firstOrFail();

                    if (ValeursLues::entiers($clientVerrouille->magasinsReels()->orderBy('id')->pluck('id')) !== ValeursLues::entiers($magasins->modelKeys())) {
                        return null;
                    }

                    $ids = array_map(fn (LienCabinet $lien): int => CleDuModele::de($lien), $liens);
                    $enCours = LienCabinet::query()->whereIn('etat', array_map(static fn (EtatDuLien $etat): string => $etat->value, EtatDuLien::enCours()))->value('id');
                    $verrouilles = LienCabinet::query()->whereKey(array_values(array_unique([...$ids, ...($enCours === null ? [] : [ValeursLues::entier($enCours)])])))->orderBy('id')->lockForUpdate()->get()->keyBy(fn (LienCabinet $lien): int => CleDuModele::de($lien));
                    $relus = array_map(fn (int $id): LienCabinet => $verrouilles->get($id) ?? throw new LogicException('Ce lien n\'existe pas.'), $ids);
                    $cabinets = array_values(array_unique([...$verrouilles->filter(fn (LienCabinet $lien): bool => $lien->cabinet_id !== null)->map(fn (LienCabinet $lien): int => (int) $lien->cabinet_id)->all(), ...$cabinetsQuiEntrent]));
                    sort($cabinets);
                    Cabinet::query()->withoutGlobalScope(SocieteScope::class)->whereIn('societe_id', $cabinets)->orderBy('id')->sharedLock()->get(['id']);
                    $comptesAvant = $verrouilles->flatMap(fn (LienCabinet $lien) => $this->acces->comptesQuiOntAcces($lien))->keyBy(fn (User $compte): int => CleDuModele::de($compte));
                    $aVerrouiller = [
                        ...$comptesAvant->keys()->all(),
                        ...ValeursLues::entiers(User::query()->whereIn('societe_id', $cabinets)->where('role_societe', RoleSociete::Proprietaire->value)->pluck('id')),
                        ...($auteur === null ? [] : [CleDuModele::de($auteur)]),
                    ];
                    $comptesVerrouilles = User::query()->with('societe')->whereKey(array_values(array_unique($aVerrouiller)))->orderBy('id')->lockForUpdate()->get()->keyBy(fn (User $compte): int => CleDuModele::de($compte));
                    DB::table('comptes_expert_comptable')->where('societe_id', $client->getKey())->whereIn('user_id', $comptesVerrouilles->keys()->all())->orderBy('id')->lockForUpdate()->get(['id']);
                    $auteurRelu = $auteur === null ? null : ($comptesVerrouilles->get(CleDuModele::de($auteur)) ?? throw new LogicException('Ce compte n\'existe pas.'));

                    $resultat = $this->operateur->auNomDe($auteurRelu, function () use ($auteurRelu, $clientVerrouille, $relus, $verrouilles, $modification, $entrees, $editeur, $visee, $magasins, $comptesAvant): mixed {
                        $avant = $this->etats($magasins, array_values($comptesAvant->all()));
                        $resultat = $modification($auteurRelu, $clientVerrouille, $relus);
                        $liensApres = LienCabinet::query()
                            ->where(fn ($apres) => $apres->whereKey($verrouilles->keys()->all())->orWhereIn('etat', array_map(static fn (EtatDuLien $etat): string => $etat->value, EtatDuLien::enCours())))
                            ->orderBy('id')
                            ->get();
                        $comptesApres = $liensApres->flatMap(fn (LienCabinet $lien) => $this->acces->comptesQuiOntAcces($lien))->keyBy(fn (User $compte): int => CleDuModele::de($compte));
                        $apres = $this->etats($magasins, array_values($comptesAvant->union($comptesApres)->sortKeys()->all()));

                        foreach ($apres as $compteId => $etat) {
                            $this->inscrireLeJet130($magasins, $etat['compte'], $avant[$compteId]['magasins'] ?? [], $etat['magasins']);
                        }

                        $concernes = $relus !== [] ? array_map(fn (LienCabinet $lien): LienCabinet => $liensApres->firstWhere('id', $lien->getKey()) ?? $lien, $relus) : array_values($liensApres->filter(fn (LienCabinet $lien): bool => ! $verrouilles->has(CleDuModele::de($lien)))->all());
                        $this->inscrireAuxJournauxDuLien($auteurRelu, $clientVerrouille, $liensApres, $concernes, $avant, $apres, $entrees, $resultat, $editeur, $visee);

                        return $resultat;
                    });

                    return [$resultat];
                });

                if ($tenu !== null) {
                    return $tenu[0];
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

        foreach ($this->comptesSansAffectation($magasin) as $compte) {
            $this->inscrireLeJet130(new Collection([$magasin]), $compte, [], [CleDuModele::de($magasin) => $compte->droitsDans($magasin)]);
        }
    }

    /**
     * Exécute ce changement du statut de ce magasin de démonstration (sa
     * fermeture ou sa réouverture, voir FermerLaDemonstrationAction et
     * OuvrirLaDemonstrationAction), qui le fait sur cette instance, puis
     * inscrit à son JET 130 ce qu'il a changé aux droits de chaque personne
     * qui y en a sans affectation, relus en base avant et après : le
     * propriétaire n'y a les autorisations de la vente que tant qu'il est
     * ouvert (voir User::autorisationsEnregistreesDans()). Le magasin doit
     * être le magasin actif, sous le verrou de son registre, dans la
     * transaction de l'appelant.
     *
     * @param  Closure(): void  $changement
     *
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function autourDuChangementDeStatut(Magasin $demonstration, Closure $changement): void
    {
        $comptes = $this->comptesSansAffectation($demonstration);
        $avant = $comptes->mapWithKeys(fn (User $compte): array => [CleDuModele::de($compte) => $compte->droitsDans($demonstration)])->all();

        $changement();

        foreach ($comptes as $compte) {
            $this->inscrireLeJet130(new Collection([$demonstration]), $compte, [CleDuModele::de($demonstration) => $avant[CleDuModele::de($compte)]], [CleDuModele::de($demonstration) => $compte->droitsDans($demonstration)]);
        }
    }

    /**
     * Les personnes qui ont des droits dans ce magasin sans y être
     * affectées, par identifiant : le propriétaire de la société, ses
     * administrateurs de tous les magasins, hors comptes retirés, et, pour
     * un magasin réel, les comptes du cabinet lié qui ont accès à la
     * société, ou ceux de son expert-comptable déclaré.
     *
     * @return Collection<int, User>
     */
    private function comptesSansAffectation(Magasin $magasin): Collection
    {
        $comptes = User::query()
            ->with('societe')
            ->where('societe_id', $magasin->societe_id)
            ->where('statut', '<>', StatutCompte::Retire->value)
            ->where(fn ($comptes) => $comptes
                ->where('role_societe', RoleSociete::Proprietaire->value)
                ->orWhereIn('id', Affectation::query()->where('societe_id', $magasin->societe_id)->whereNull('magasin_id')->select('user_id')))
            ->orderBy('id')
            ->get();

        $lien = $magasin->est_demonstration ? null : LienCabinet::query()->withoutGlobalScope(SocieteScope::class)->where('societe_id', $magasin->societe_id)->whereIn('etat', array_map(static fn (EtatDuLien $etat): string => $etat->value, EtatDuLien::enCours()))->first();

        return $lien === null ? $comptes : $comptes->concat($this->acces->comptesQuiOntAcces($lien));
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
            $touches = $societe->membres()->whereKey($comptes)->orderBy('id')->get()
                ->flatMap(fn (User $compte) => $this->acces->magasinsDuCompte($compte))
                ->concat(Magasin::query()->with('societe')->whereKey($vises)->where('est_demonstration', false)->get());

            return new Collection($touches->unique(fn (Magasin $magasin): int => CleDuModele::de($magasin))->sortBy(fn (Magasin $magasin): int => CleDuModele::de($magasin))->values()->all());
        }

        $societeId = $societe->getKey();
        $tous = User::query()->whereKey($comptes)->where('societe_id', $societeId)->where(fn ($touchant) => $touchant
            ->whereIn('role_societe', [RoleSociete::Proprietaire->value, RoleSociete::ExpertComptable->value])
            ->orWhereIn('id', Affectation::query()->where('societe_id', $societeId)->whereNull('magasin_id')->select('user_id')))->exists();

        $magasins = $societe->magasins();

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
            $relu = User::query()->whereKey($compte->getKey())->firstOrFail();
            $etats[CleDuModele::de($relu)] = [
                'compte' => $relu,
                'societe' => [
                    'role_societe' => $relu->role_societe->value,
                    'statut' => $relu->statut->value,
                    'autorisations_societe' => $relu->autorisationsDeSociete(),
                ],
                'magasins' => $magasins->mapWithKeys(fn (Magasin $magasin): array => [CleDuModele::de($magasin) => $relu->droitsDans($magasin)])->all(),
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
     * au journal de son cabinet, ni l'inverse. Une procédure de l'éditeur
     * n'inscrit sa justification et ses opérateurs qu'au journal de la
     * société qu'elle vise.
     *
     * @template TResultat
     *
     * @param  Collection<int, LienCabinet>  $liens
     * @param  list<LienCabinet>  $concernes  les liens dont parlent les entrées, la première étant leur cible
     * @param  array<int, array{compte: User, societe: array<string, mixed>, magasins: array<int, array<string, mixed>|null>}>  $avant
     * @param  array<int, array{compte: User, societe: array<string, mixed>, magasins: array<int, array<string, mixed>|null>}>  $apres
     * @param  Closure(Societe, list<LienCabinet>, TResultat): list<array{0: ActionAuditee, 1: array<string, mixed>, 2: int|null}>  $entrees  les entrées de chaque journal, d'après ce que la modification a rendu (la société dont l'entrée porte les droits des comptes, nulle pour une entrée sans droits)
     * @param  TResultat  $resultat  ce que la modification a rendu
     */
    private function inscrireAuxJournauxDuLien(?User $auteur, Societe $client, Collection $liens, array $concernes, array $avant, array $apres, Closure $entrees, mixed $resultat, ?InterventionDeLEditeur $editeur, ?Societe $visee): void
    {
        $cabinets = $liens->filter(fn (LienCabinet $lien): bool => $lien->cabinet_id !== null)->map(fn (LienCabinet $lien): int => (int) $lien->cabinet_id)->unique()->sort()->values();
        $journaux = [$client, ...$cabinets->map(fn (int $id): Societe => Societe::query()->findOrFail($id))->all()];
        $duVisiteur = $auteur->societe_id ?? CleDuModele::de($client);

        foreach ($journaux as $journal) {
            foreach ($entrees($journal, $concernes, $resultat) as [$action, $valeurs, $cabinetId]) {
                $droitsAvant = [];
                $droitsApres = [];

                foreach ($apres as $compteId => $etat) {
                    if ($cabinetId === null || (int) $etat['compte']->societe_id !== (int) $cabinetId) {
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
                    $journal->is($client) || ($auteur !== null && in_array((int) $auteur->societe_id, [CleDuModele::de($journal), CleDuModele::de($client)], true)) ? $auteur : null,
                    null,
                    $cabinetId === null ? null : ['droits' => $droitsAvant],
                    $cabinetId === null ? $valeurs : ['droits' => $droitsApres, ...$valeurs],
                    $concernes[0] ?? null,
                    $editeur,
                    detailsDeLEditeur: $visee === null || $journal->is($visee),
                ));
                CleDuModele::de($journal) === $duVisiteur ? $inscrire() : $this->traces->horsDeSaSociete($inscrire);
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
            $droitsAvant = $avant[CleDuModele::de($magasin)] ?? null;
            $droitsApres = $apres[CleDuModele::de($magasin)] ?? null;

            if ($droitsAvant === $droitsApres) {
                continue;
            }

            $journaliser = fn () => app(TenantContext::class)->runAs($magasin, fn () => $this->registre->journaliser(CodeEvenement::ModificationDesDroits, [
                'membre' => ['id' => $compte->getKey(), 'nom' => $compte->nomPourLHistorique()],
                'avant' => $droitsAvant,
                'apres' => $droitsApres,
            ]));

            $dansSaSociete = fn () => (int) $magasin->societe_id === CleDuModele::de(app(TenantContext::class)->societe())
                ? $journaliser()
                : app(TenantContext::class)->runAsSociete($magasin->societe, $journaliser);
            $operateur = $this->operateur->actuel();
            $operateur === null || (int) $operateur->societe_id === (int) $magasin->societe_id ? $dansSaSociete() : $this->traces->horsDeSaSociete($dansSaSociete);
        }
    }

    /**
     * Le compte que la modification a créé, qu'elle le rende seul ou dans
     * son résultat (« compte ») ; aucun sinon.
     */
    private static function compteCree(mixed $resultat): ?User
    {
        return match (true) {
            $resultat instanceof User => $resultat,
            is_array($resultat) && ($resultat['compte'] ?? null) instanceof User => $resultat['compte'],
            default => null,
        };
    }
}
