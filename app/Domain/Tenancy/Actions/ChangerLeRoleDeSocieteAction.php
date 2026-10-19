<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Identifiant;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Services\AffectationsDesMembres;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesAdministrateurs;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * Le propriétaire, et lui seul, change le rôle de société d'un compte
 * actif : membre, administrateur ou expert-comptable. Le compte, son
 * identifiant et son historique restent les mêmes ; seuls son rôle et ses
 * autorisations changent (voir PorteeDesDroits::peutChangerLeRoleDeSociete()) :
 * - vers administrateur : son affectation de membre disparaît, puis il
 *   reçoit la portée et les cases que le propriétaire choisit, précochées
 *   selon le modèle de l'administrateur, aucune de la vente ; son magasin
 *   de rattachement reste inscrit sans limiter sa portée ; ses ventes
 *   passées restent à son nom ;
 * - vers membre : dans le magasin de rattachement de son identifiant ; un
 *   compte qui n'en a pas (né administrateur, expert-comptable ou
 *   propriétaire) reçoit le magasin réel ouvert que le propriétaire lui
 *   choisit, écrit une fois pour toutes ; un magasin de rattachement fermé
 *   ou cédé refuse le changement ;
 * - vers expert-comptable : sa portée, son affectation et ses autorisations
 *   de société disparaissent ; il lit tous les magasins réels.
 * Jamais pour un compte d'entraînement (rattaché au magasin de
 * démonstration), que la base refuse aussi (voir GardesDesDroits). Les
 * droits avant et après s'inscrivent au JET 130 de chaque magasin touché,
 * puis au journal d'audit (RoleDeSocieteChange), avec l'ancien et le
 * nouveau rôle, la date et l'auteur (voir JournalDesDroits). Dans l'ordre
 * qu'exigent les gardes de la base : les droits d'avant s'effacent, le
 * rôle change, le magasin de rattachement s'écrit, puis les droits
 * nouveaux.
 */
class ChangerLeRoleDeSocieteAction
{
    use AgitDansLaSociete;

    public const MESSAGE_ENTRAINEMENT = 'Un compte d\'entraînement ne change pas de rôle : créez un compte dans un magasin réel.';

    public const MESSAGE_DEJA_CE_ROLE = 'Ce compte a déjà ce rôle.';

    public const MESSAGE_MAGASIN_A_CHOISIR = 'Choisissez le magasin réel ouvert de cette personne : il sera son magasin pour toujours.';

    public const MESSAGE_MAGASIN_FERME = 'Son magasin est fermé : créez-lui un compte dans un magasin ouvert.';

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly PorteeDesAdministrateurs $porteeDesAdministrateurs,
        private readonly AffectationsDesMembres $affectations,
        private readonly PorteeDesDroits $portee,
    ) {}

    /**
     * @param  list<int>|null  $magasins  null : tous les magasins, présents et futurs
     * @param  array<int|string, mixed>  $autorisationsMagasin
     * @param  array<array-key, mixed>  $autorisationsSociete
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function versAdministrateur(User $auteur, User $compte, ?array $magasins, array $autorisationsMagasin, array $autorisationsSociete): User
    {
        $societe = $compte->societe()->firstOrFail();

        return $this->changer($auteur, $compte, $societe, RoleSociete::Administrateur, function () use ($societe, $magasins, $autorisationsMagasin, $autorisationsSociete): array {
            $portee = $this->porteeDesAdministrateurs->lire($societe, $magasins, $autorisationsMagasin, $autorisationsSociete);

            return [$this->porteeDesAdministrateurs->magasinsTouches($societe, $portee), function (User $auteurRelu, User $compteRelu) use ($portee): void {
                $this->porteeDesAdministrateurs->effacer($compteRelu);
                $compteRelu->forceFill(['role_societe' => RoleSociete::Administrateur])->save();
                $this->porteeDesAdministrateurs->ecrire($compteRelu, $portee);
            }];
        });
    }

    /**
     * @param  list<string>|null  $autorisations  les cases d'un vendeur ou d'un observateur ; null, le modèle de son rôle
     * @param  int|null  $magasinId  le magasin réel ouvert que le propriétaire choisit pour un compte qui n'a pas encore de magasin de rattachement
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function versMembre(User $auteur, User $compte, RoleMagasin|string $role, ?array $autorisations = null, ?int $magasinId = null): User
    {
        $societe = $compte->societe()->firstOrFail();
        $demandee = $this->affectations->lire($role, $autorisations);

        return $this->changer($auteur, $compte, $societe, RoleSociete::Membre, function () use ($compte, $societe, $magasinId, $demandee): array {
            $magasin = $this->magasinDuMembre($compte, $societe, $magasinId);

            return [new Collection([$magasin]), function (User $auteurRelu, User $compteRelu) use ($societe, $magasinId, $demandee): void {
                $magasin = $this->magasinDuMembre($compteRelu, $societe, $magasinId);
                $cases = $this->affectations->cases($auteurRelu, $magasin, $demandee);
                $this->porteeDesAdministrateurs->effacer($compteRelu);
                $compteRelu->forceFill(['role_societe' => RoleSociete::Membre])->save();
                Identifiant::query()->where('user_id', $compteRelu->getKey())->whereNull('magasin_id')->update(['magasin_id' => $magasin->getKey()]);
                $this->affectations->ecrire($auteurRelu, $compteRelu, $magasin, $demandee['role'], $cases);
            }];
        });
    }

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function versExpertComptable(User $auteur, User $compte): User
    {
        $societe = $compte->societe()->firstOrFail();

        return $this->changer($auteur, $compte, $societe, RoleSociete::ExpertComptable, fn (): array => [
            Magasin::query()->where('societe_id', $societe->getKey())->orderBy('id')->get(),
            function (User $auteurRelu, User $compteRelu): void {
                $this->porteeDesAdministrateurs->effacer($compteRelu);
                $compteRelu->forceFill(['role_societe' => RoleSociete::ExpertComptable])->save();
            },
        ]);
    }

    /**
     * Le changement, sur l'auteur et le compte relus sous les verrous des
     * registres des magasins touchés et de leurs lignes.
     *
     * @param  Closure(): array{0: Collection<int, Magasin>, 1: Closure(User, User): void}  $preparer  les magasins que vise le nouveau rôle, et l'écriture
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    private function changer(User $auteur, User $compte, Societe $societe, RoleSociete $nouveauRole, Closure $preparer): User
    {
        $this->dansLaSociete($societe, CreerAdministrateurAction::MESSAGE_PROPRIETAIRE_SEUL, function () use ($auteur, $compte, $societe, $nouveauRole, $preparer): void {
            CreerAdministrateurAction::exigerLeProprietaire($auteur, $societe);
            $this->exigerUnChangement($auteur, $compte, $nouveauRole);
            [$magasins, $ecrire] = $preparer();

            $this->journalDesDroits->modifier($auteur, ActionAuditee::RoleDeSocieteChange, [$compte], $magasins, function (User $auteurRelu, array $relus) use ($societe, $nouveauRole, $ecrire): void {
                [$compteRelu] = $relus;
                CreerAdministrateurAction::exigerLeProprietaire($auteurRelu, $societe);
                $this->exigerUnChangement($auteurRelu, $compteRelu, $nouveauRole);
                $ecrire($auteurRelu, $compteRelu);
            });
        });

        return $compte->refresh();
    }

    /**
     * @throws AuthorizationException
     * @throws LogicException
     */
    private function exigerUnChangement(User $auteur, User $compte, RoleSociete $nouveauRole): void
    {
        if ($compte->estUnCompteDEntrainement()) {
            throw new LogicException(self::MESSAGE_ENTRAINEMENT);
        }

        if (! $this->portee->peutChangerLeRoleDeSociete($auteur, $compte)) {
            throw new AuthorizationException(PorteeDesDroits::REFUS);
        }

        if ($compte->role_societe === $nouveauRole) {
            throw new LogicException(self::MESSAGE_DEJA_CE_ROLE);
        }
    }

    /**
     * Le magasin où ce compte devient membre : celui de son identifiant,
     * qui doit être ouvert ; sinon le magasin réel ouvert choisi par le
     * propriétaire.
     *
     * @throws LogicException
     */
    private function magasinDuMembre(User $compte, Societe $societe, ?int $magasinId): Magasin
    {
        $rattachement = $compte->magasinDeSonIdentifiant();

        if ($rattachement !== null) {
            if ($rattachement->statut !== StatutMagasin::Actif) {
                throw new LogicException(self::MESSAGE_MAGASIN_FERME);
            }

            return $rattachement;
        }

        $choisi = $magasinId === null ? null : Magasin::query()
            ->where('societe_id', $societe->getKey())
            ->where('est_demonstration', false)
            ->where('statut', StatutMagasin::Actif->value)
            ->find($magasinId);

        return $choisi ?? throw new LogicException(self::MESSAGE_MAGASIN_A_CHOISIR);
    }
}
