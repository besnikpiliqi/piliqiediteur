<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Authorization\OperateurCourant;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\HorlogeRegressiveException;
use App\Domain\CaisseSecurisee\Exceptions\ModuleFiscalNonConfigureException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use Closure;
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
 * touché, ses droits avant et après. Le propriétaire, un administrateur de
 * tous les magasins et un expert-comptable touchent tous les magasins de
 * la société ; tout autre compte, ceux de ses affectations, avant comme
 * après.
 *
 * Une modification se fait dans une transaction qui verrouille d'abord les
 * registres de ces magasins, par identifiant croissant (voir
 * RegistreFiscal::sousVerrouDesMagasins()), puis les lignes de l'auteur et
 * des comptes visés, par identifiant croissant : deux modifications des
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
 */
class JournalDesDroits
{
    private const ESSAIS_SI_LES_MAGASINS_CHANGENT = 3;

    public function __construct(
        private readonly RegistreFiscal $registre,
        private readonly JournalAudit $journalAudit,
        private readonly OperateurCourant $operateur,
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
     * @return TResultat
     *
     * @throws LogicException
     * @throws ModuleFiscalNonConfigureException
     * @throws HorlogeRegressiveException
     */
    public function modifier(?User $auteur, ActionAuditee $action, array $comptes, iterable $magasinsVises, Closure $modification): mixed
    {
        $vises = collect($magasinsVises)->map(fn (Magasin $magasin): int => (int) $magasin->getKey())->all();
        $ids = array_map(fn (User $compte): int => (int) $compte->getKey(), $comptes);

        for ($essai = 1; $essai <= self::ESSAIS_SI_LES_MAGASINS_CHANGENT; $essai++) {
            $magasins = $this->magasinsTouches($ids, $vises);
            $termine = false;

            $resultat = $this->registre->sousVerrouDesMagasins($magasins, function () use ($auteur, $action, $ids, $vises, $magasins, $modification, &$termine): mixed {
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
     * Inscrit, au JET 130 de ce nouveau magasin, les droits de chaque
     * personne qui y en a sans affectation (le propriétaire de la société,
     * ses administrateurs de tous les magasins, ses experts-comptables pour
     * un magasin réel), avant nul : le magasin doit être le magasin actif,
     * dans la transaction de l'appelant (voir MiseEnActiviteDesMagasins et
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
                ->whereIn('role_societe', [RoleSociete::Proprietaire->value, RoleSociete::ExpertComptable->value])
                ->orWhereIn('id', Affectation::query()->where('societe_id', $magasin->societe_id)->whereNull('magasin_id')->select('user_id')))
            ->orderBy('id')
            ->get();

        foreach ($comptes as $compte) {
            $this->inscrireLeJet130(new Collection([$magasin]), $compte, [], [$magasin->getKey() => $compte->droitsDans($magasin)]);
        }
    }

    /**
     * Les magasins que touchent les droits actuels de ces comptes, relus en
     * base, et ceux que visent leurs nouveaux droits, par identifiant.
     *
     * @param  list<int>  $comptes
     * @param  list<int>  $vises
     * @return Collection<int, Magasin>
     */
    private function magasinsTouches(array $comptes, array $vises): Collection
    {
        $societeId = app(TenantContext::class)->societe()->getKey();
        $tous = User::query()->whereKey($comptes)->where('societe_id', $societeId)->where(fn ($touchant) => $touchant
            ->whereIn('role_societe', [RoleSociete::Proprietaire->value, RoleSociete::ExpertComptable->value])
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
            'nom' => $etat['compte']->name,
            ...$etat['societe'],
            'magasins' => array_filter($etat['magasins'], static fn (?array $droits): bool => $droits !== null),
        ];
    }

    /**
     * Inscrit au JET 130 de chaque magasin ce qui y a changé pour ce compte.
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

            app(TenantContext::class)->runAs($magasin, fn () => $this->registre->journaliser(CodeEvenement::ModificationDesDroits, [
                'membre' => ['id' => $compte->getKey(), 'nom' => $compte->name],
                'avant' => $droitsAvant,
                'apres' => $droitsApres,
            ]));
        }
    }
}
