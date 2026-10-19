<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\AffectationsDesMembres;
use App\Domain\Tenancy\Services\JournalDesDroits;
use App\Domain\Tenancy\Services\PorteeDesAdministrateurs;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Domain\Tenancy\Services\SessionsDesComptes;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Les gestes qui touchent tout un compte de la société, selon les règles
 * de PorteeDesDroits, sur l'auteur et le compte relus sous les verrous des
 * registres de tous les magasins du compte (tous les magasins de la
 * société pour un administrateur de tous les magasins ou un
 * expert-comptable) et de leurs lignes, avec le JET 130 de chacun et
 * l'entrée d'audit (voir JournalDesDroits) :
 * - suspendre : le compte n'ouvre plus rien et n'a plus aucun droit dès la
 *   requête suivante, ses sessions se ferment et ses notifications, qui ne
 *   le regardent plus, s'effacent ; il garde son affectation ;
 * - réactiver : un compte suspendu par le propriétaire ne se réactive que
 *   par le propriétaire ;
 * - retirer de la société (retirer un membre de son magasin, son seul
 *   magasin, revient au même) : ses affectations et ses autorisations de
 *   société disparaissent, ses notifications s'effacent, ses sessions se
 *   ferment ; le compte ne s'efface jamais et garde son rôle de société,
 *   son identifiant, son magasin de rattachement et son historique. Un
 *   compte suspendu par le propriétaire ne se retire que par le
 *   propriétaire ;
 * - réintégrer un compte retiré, dans son rôle et, pour un membre, dans son
 *   magasin, selon les règles de la création de ce rôle : comme membre
 *   (voir PorteeDesDroits::peutReintegrer()), comme administrateur ou comme
 *   expert-comptable par le propriétaire seul. Un compte retiré par le
 *   propriétaire ne se réintègre que par le propriétaire.
 *
 * Chaque nouveau statut note qui l'a donné, et si c'était alors le
 * propriétaire (voir User::nouveauStatut()) : la décision du propriétaire
 * tient après une transmission de la propriété.
 */
class ChangerStatutCompteAction
{
    use AgitDansLaSociete;

    public const MESSAGE_DEJA_DANS_CE_STATUT = 'Ce compte est déjà dans ce statut.';

    public function __construct(
        private readonly JournalDesDroits $journalDesDroits,
        private readonly PorteeDesDroits $portee,
        private readonly AffectationsDesMembres $affectations,
        private readonly SessionsDesComptes $sessions,
        private readonly PorteeDesAdministrateurs $porteeDesAdministrateurs,
    ) {}

    /**
     * @throws AuthorizationException
     */
    public function suspendre(User $auteur, User $compte): void
    {
        $this->changer($auteur, $compte, ActionAuditee::CompteSuspendu, function (User $auteurRelu, User $compteRelu): void {
            if (! $compteRelu->estActif()) {
                throw new LogicException(self::MESSAGE_DEJA_DANS_CE_STATUT);
            }

            if (! $this->portee->peutSuspendreOuReactiver($auteurRelu, $compteRelu)) {
                throw new AuthorizationException(PorteeDesDroits::REFUS);
            }

            $compteRelu->forceFill($compteRelu->nouveauStatut(StatutCompte::Suspendu, $auteurRelu))->save();
            $compteRelu->effacerToutesSesNotifications();
            $this->sessions->fermer($compteRelu);
        });
    }

    /**
     * @throws AuthorizationException
     */
    public function reactiver(User $auteur, User $compte): void
    {
        $this->changer($auteur, $compte, ActionAuditee::CompteReactive, function (User $auteurRelu, User $compteRelu): void {
            if (! $compteRelu->estSuspendu()) {
                throw new LogicException(self::MESSAGE_DEJA_DANS_CE_STATUT);
            }

            if (! $this->portee->peutSuspendreOuReactiver($auteurRelu, $compteRelu)) {
                throw new AuthorizationException(PorteeDesDroits::REFUS);
            }

            $compteRelu->forceFill($compteRelu->nouveauStatut(StatutCompte::Actif, $auteurRelu))->save();
        });
    }

    /**
     * @throws AuthorizationException
     */
    public function retirer(User $auteur, User $compte): void
    {
        $this->changer($auteur, $compte, ActionAuditee::CompteRetire, function (User $auteurRelu, User $compteRelu): void {
            if (! $this->portee->peutRetirerDeLaSociete($auteurRelu, $compteRelu)) {
                throw new AuthorizationException(PorteeDesDroits::REFUS);
            }

            self::retirerLesDroits($compteRelu);
            $compteRelu->forceFill($compteRelu->nouveauStatut(StatutCompte::Retire, $auteurRelu))->save();
            $compteRelu->effacerToutesSesNotifications();
            $this->sessions->fermer($compteRelu);
        });
    }

    /**
     * Réintègre un compte de membre retiré dans le magasin de son
     * identifiant, avec ce rôle et ces cases, comme une création (voir
     * PorteeDesDroits::peutReintegrer()).
     *
     * @param  list<string>|null  $autorisations  les cases d'un vendeur ou d'un observateur ; null, le modèle de son rôle
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function reintegrer(User $auteur, User $compte, RoleMagasin|string $role, ?array $autorisations = null): void
    {
        $societe = $compte->societe()->firstOrFail();
        $demandee = $this->affectations->lire($role, $autorisations);
        $magasin = $compte->magasinDeSonIdentifiant() ?? throw new AuthorizationException(PorteeDesDroits::REFUS);

        $this->dansLaSociete($societe, PorteeDesDroits::REFUS, function () use ($auteur, $compte, $demandee, $magasin): void {
            $ecrite = $this->journalDesDroits->modifier($auteur, ActionAuditee::CompteReintegre, [$compte], [$magasin], function (User $auteurRelu, array $relus) use ($demandee, $magasin) {
                [$compteRelu] = $relus;

                if (! $this->portee->peutReintegrer($auteurRelu, $compteRelu, $demandee['role'])) {
                    throw new AuthorizationException(PorteeDesDroits::REFUS);
                }

                $cases = $this->affectations->cases($auteurRelu, $magasin, $demandee);
                $compteRelu->forceFill($compteRelu->nouveauStatut(StatutCompte::Actif, $auteurRelu))->save();

                return $this->affectations->ecrire($auteurRelu, $compteRelu, $magasin, $demandee['role'], $cases);
            });

            $this->affectations->prevenirDUneNomination($compte, $ecrite, compteCree: false);
        });

        $compte->refresh();
    }

    /**
     * Réintègre un administrateur retiré, avec la portée et les
     * autorisations que le propriétaire, seul, lui choisit, comme à sa
     * création (voir PorteeDesAdministrateurs).
     *
     * @param  list<int>|null  $magasins  null : tous les magasins, présents et futurs
     * @param  array<int|string, mixed>  $autorisationsMagasin
     * @param  array<array-key, mixed>  $autorisationsSociete
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function reintegrerLAdministrateur(User $auteur, User $compte, ?array $magasins, array $autorisationsMagasin, array $autorisationsSociete): void
    {
        $societe = $compte->societe()->firstOrFail();

        $this->dansLaSociete($societe, PorteeDesDroits::REFUS, function () use ($auteur, $compte, $societe, $magasins, $autorisationsMagasin, $autorisationsSociete): void {
            CreerAdministrateurAction::exigerLeProprietaire($auteur, $societe);
            $portee = $this->porteeDesAdministrateurs->lire($societe, $magasins, $autorisationsMagasin, $autorisationsSociete);

            $this->journalDesDroits->modifier($auteur, ActionAuditee::CompteReintegre, [$compte], $this->porteeDesAdministrateurs->magasinsTouches($societe, $portee), function (User $auteurRelu, array $relus) use ($societe, $portee): void {
                [$compteRelu] = $relus;
                CreerAdministrateurAction::exigerLeProprietaire($auteurRelu, $societe);

                if (! $compteRelu->estRetire() || ! $compteRelu->estAdministrateur() || (int) $compteRelu->societe_id !== $societe->getKey()) {
                    throw new AuthorizationException(PorteeDesDroits::REFUS);
                }

                $compteRelu->forceFill($compteRelu->nouveauStatut(StatutCompte::Actif, $auteurRelu))->save();
                $this->porteeDesAdministrateurs->ecrire($compteRelu, $portee);
            });
        });

        $compte->refresh();
    }

    /**
     * Réintègre un expert-comptable retiré : le propriétaire seul, tant
     * qu'il gère son équipe (pas dans une société révoquée, voir
     * PorteeDesDroits::gereSonEquipe()).
     *
     * @throws AuthorizationException
     */
    public function reintegrerLExpertComptable(User $auteur, User $compte): void
    {
        $societe = $compte->societe()->firstOrFail();

        $this->dansLaSociete($societe, PorteeDesDroits::REFUS, function () use ($auteur, $compte, $societe): void {
            $this->journalDesDroits->modifier($auteur, ActionAuditee::CompteReintegre, [$compte], Magasin::query()->where('societe_id', $societe->getKey())->get(), function (User $auteurRelu, array $relus) use ($societe): void {
                [$compteRelu] = $relus;
                CreerExpertComptableAction::exigerLeProprietaire($auteurRelu, $societe);

                if (! $compteRelu->estRetire() || ! $compteRelu->estExpertComptable() || (int) $compteRelu->societe_id !== $societe->getKey()) {
                    throw new AuthorizationException(PorteeDesDroits::REFUS);
                }

                $compteRelu->forceFill($compteRelu->nouveauStatut(StatutCompte::Actif, $auteurRelu))->save();
            });
        });

        $compte->refresh();
    }

    /**
     * Supprime les affectations et les autorisations de société d'un compte
     * qui quitte la société : il garde son rôle de société, son identifiant
     * et son magasin de rattachement, sans aucun droit.
     */
    public static function retirerLesDroits(User $compte): void
    {
        Affectation::query()->where('user_id', $compte->getKey())->delete();
        DB::table('autorisations_societe')->where('user_id', $compte->getKey())->delete();
    }

    /**
     * @param  Closure(User, User): void  $geste
     *
     * @throws AuthorizationException
     */
    private function changer(User $auteur, User $compte, ActionAuditee $action, Closure $geste): void
    {
        $this->dansLaSociete($compte->societe()->firstOrFail(), PorteeDesDroits::REFUS, function () use ($auteur, $compte, $action, $geste): void {
            $this->journalDesDroits->modifier($auteur, $action, [$compte], [], function (User $auteurRelu, array $relus) use ($geste): void {
                $geste($auteurRelu, $relus[0]);
            });
        });

        $compte->refresh();
    }
}
