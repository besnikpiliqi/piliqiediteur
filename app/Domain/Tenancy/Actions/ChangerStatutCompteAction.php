<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Concerns\AgitDansLaSociete;
use App\Domain\Tenancy\Enums\ActionAuditee;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\AffectationsDesMembres;
use App\Domain\Tenancy\Services\JournalDesDroits;
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
 *   le regardent plus, s'effacent ; il garde ses affectations ;
 * - réactiver : un compte suspendu par le propriétaire ne se réactive que
 *   par lui ;
 * - retirer de la société : ses affectations et ses autorisations de
 *   société disparaissent, un administrateur ou un expert-comptable
 *   repasse membre, ses notifications s'effacent, ses sessions se
 *   ferment ; le compte ne s'efface jamais et garde son adresse ;
 * - réintégrer un compte retiré par la société, comme une création : comme
 *   membre (voir PorteeDesDroits::peutReintegrer()), ou comme
 *   expert-comptable par le propriétaire seul. Un compte retiré par le
 *   propriétaire ne se réintègre que par lui ; un compte retiré par son
 *   propre refus, jamais (seulement par un rattachement qu'il accepte).
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

            $compteRelu->forceFill(['statut' => StatutCompte::Suspendu, 'statut_change_par' => $auteurRelu->getKey()])->save();
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

            $compteRelu->forceFill(['statut' => StatutCompte::Actif, 'statut_change_par' => $auteurRelu->getKey()])->save();
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
            $compteRelu->forceFill(['statut' => StatutCompte::Retire, 'statut_change_par' => $auteurRelu->getKey()])->save();
            $compteRelu->effacerToutesSesNotifications();
            $this->sessions->fermer($compteRelu);
        });
    }

    /**
     * Réintègre comme membre un compte retiré par la société, avec ses
     * nouvelles affectations, comme une création (voir
     * PorteeDesDroits::peutReintegrer()).
     *
     * @param  array<int, array{role: RoleMagasin|string, autorisations?: list<string>|null}>  $affectations  par identifiant de magasin
     *
     * @throws AuthorizationException
     * @throws LogicException
     */
    public function reintegrer(User $auteur, User $compte, array $affectations): void
    {
        $societe = $compte->societe()->firstOrFail();

        $this->dansLaSociete($societe, PorteeDesDroits::REFUS, function () use ($auteur, $compte, $affectations, $societe): void {
            $demandees = array_filter($this->affectations->lire($societe, $affectations));
            $ecrites = [];

            $this->journalDesDroits->modifier($auteur, ActionAuditee::CompteReintegre, [$compte], array_map(fn (array $demandee): Magasin => $demandee['magasin'], $demandees), function (User $auteurRelu, array $relus) use ($demandees, &$ecrites): void {
                [$compteRelu] = $relus;

                if (! $this->portee->peutReintegrer($auteurRelu, $compteRelu, array_map(fn (array $demandee): RoleMagasin => $demandee['role'], $demandees))) {
                    throw new AuthorizationException(PorteeDesDroits::REFUS);
                }

                $verifiees = $this->affectations->verifierPourUnNouveauCompte($auteurRelu, $demandees);
                $compteRelu->forceFill(['statut' => StatutCompte::Actif, 'statut_change_par' => $auteurRelu->getKey()])->save();

                foreach ($verifiees as $affectation) {
                    $ecrites[] = $this->affectations->ecrire($auteurRelu, $compteRelu, $affectation['magasin'], $affectation['role'], $affectation['autorisations']);
                }
            });

            $this->affectations->prevenirDesNominations($compte, $ecrites);
        });
    }

    /**
     * Réintègre comme expert-comptable un compte retiré par la société : le
     * propriétaire seul.
     *
     * @throws AuthorizationException
     */
    public function reintegrerCommeExpertComptable(User $auteur, User $compte): void
    {
        $societe = $compte->societe()->firstOrFail();

        $this->dansLaSociete($societe, PorteeDesDroits::REFUS, function () use ($auteur, $compte, $societe): void {
            $this->journalDesDroits->modifier($auteur, ActionAuditee::CompteReintegre, [$compte], Magasin::query()->where('societe_id', $societe->getKey())->get(), function (User $auteurRelu, array $relus): void {
                [$compteRelu] = $relus;

                if (! $auteurRelu->peutAgir() || ! $auteurRelu->estProprietaire() || (int) $compteRelu->societe_id !== (int) $auteurRelu->societe_id
                    || ! $compteRelu->estRetire() || ! $compteRelu->estMembre() || (int) $compteRelu->statut_change_par === (int) $compteRelu->getKey()
                    || ($compteRelu->doitChangerSonMotDePasse() && (int) $compteRelu->cree_par !== (int) $auteurRelu->getKey())) {
                    throw new AuthorizationException(PorteeDesDroits::REFUS);
                }

                $compteRelu->forceFill(['role_societe' => RoleSociete::ExpertComptable, 'statut' => StatutCompte::Actif, 'statut_change_par' => $auteurRelu->getKey()])->save();
            });
        });
    }

    /**
     * Supprime les affectations et les autorisations de société d'un compte
     * qui quitte la société, puis le repasse membre, dans cet ordre (voir
     * GardesDesDroits) : un administrateur ou un expert-comptable retiré ne
     * garde aucun rôle de société.
     */
    public static function retirerLesDroits(User $compte): void
    {
        Affectation::query()->where('user_id', $compte->getKey())->delete();
        DB::table('autorisations_societe')->where('user_id', $compte->getKey())->delete();

        if (! $compte->estMembre()) {
            $compte->forceFill(['role_societe' => RoleSociete::Membre])->save();
        }
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
