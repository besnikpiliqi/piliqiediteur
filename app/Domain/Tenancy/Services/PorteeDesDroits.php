<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Qui gère quel compte, écrit une seule fois, et appliqué par les actions
 * de l'équipe (voir CreerMembreAction, ModifierMembreAction...), par
 * MembrePolicy et AffectationPolicy, et par les écrans. Chaque méthode
 * reçoit des comptes relus en base (sous les verrous de JournalDesDroits
 * quand une action écrit) et lit les affectations en base, sans mémoire.
 *
 * Règles générales :
 * - aucun geste sur un compte d'une autre société, ni sur son propre
 *   compte (ses droits, son rôle, ses affectations, son statut), ni sur le
 *   propriétaire ;
 * - seul le propriétaire gère les administrateurs et les experts-comptables,
 *   et seul il affecte une même personne à plusieurs magasins (voir
 *   peutAffecterAPlusieursMagasins()) ;
 * - responsable d'un magasin : nommé, retiré ou déplacé par le propriétaire,
 *   ou par un administrateur autorisé à nommer les responsables, dans les
 *   magasins de sa portée (voir peutNommerUnResponsable()) ; rétrogradé,
 *   suspendu ou réactivé par le propriétaire seul ; jamais touché par un
 *   autre responsable ;
 * - vendeur ou observateur : créé dans un magasin, coché, modifié et
 *   retiré par le propriétaire ou un responsable du magasin ; un
 *   responsable ne gère que les vendeurs et les observateurs dont tous les
 *   magasins sont des magasins dont il est responsable (voir
 *   gereLeCompte()), tout autre compte se gère par le propriétaire seul ;
 * - gestes sur tout le compte (suspendre, réactiver, retirer de la
 *   société, renouveler l'accès) : le propriétaire ; un responsable de
 *   tous les magasins d'un vendeur ou d'un observateur ; pour une personne
 *   qui n'est que responsable de magasins tous dans sa portée, un
 *   administrateur autorisé, pour la retirer de la société ou lui envoyer
 *   un lien ; un compte suspendu par le propriétaire ne se réactive que par
 *   lui ;
 * - un nouveau mot de passe provisoire ne se donne qu'à un compte dont le
 *   mot de passe est encore un provisoire, par le propriétaire ou un
 *   responsable de tous ses magasins, jamais par un administrateur ;
 * - tant que son mot de passe est un provisoire, un compte ne reçoit un
 *   nouveau magasin, un rôle de responsable ou une réintégration que de la
 *   personne qui l'a créé : qui connaît le provisoire ne profite jamais,
 *   par ce compte, de droits qu'il ne pourrait pas donner lui-même ;
 * - ce qu'on coche pour quelqu'un dans un magasin reste inclus dans ses
 *   propres autorisations dans ce magasin : toutes pour le propriétaire et
 *   un responsable du magasin, aucune pour tout autre compte (voir
 *   autorisationsCochables()).
 *
 * Retirer quelqu'un de son dernier magasin le retire de la société : n'en
 * a le droit que qui peut le retirer de la société.
 */
final class PorteeDesDroits
{
    public const REFUS = 'Vous ne pouvez pas faire cela pour ce compte.';

    public const REFUS_AU_DELA = 'Vous ne pouvez pas donner, dans ce magasin, une autorisation que vous n\'avez pas.';

    public const REFUS_PLUSIEURS_MAGASINS = 'Seul le propriétaire affecte une même personne à plusieurs magasins.';

    public const REFUS_PROVISOIRE = 'Cette personne n\'a pas encore choisi son mot de passe : seule la personne qui a créé son compte peut lui donner un nouveau magasin, un rôle de responsable ou la réintégrer.';

    /**
     * Gère-t-il l'équipe de ce magasin (ses vendeurs et ses observateurs) ?
     * Le propriétaire de la société du magasin, ou un responsable de ce
     * magasin, avec un compte qui peut agir.
     */
    public function gereLEquipeDe(User $auteur, Magasin $magasin): bool
    {
        return $auteur->peutAgir()
            && (int) $magasin->societe_id === (int) $auteur->societe_id
            && ($auteur->estProprietaire() || $auteur->estResponsableDe($magasin));
    }

    /**
     * Peut-il nommer, retirer ou déplacer un responsable dans ce magasin ?
     * Le propriétaire ; un administrateur autorisé à nommer les
     * responsables, pour un magasin de sa portée.
     */
    public function peutNommerUnResponsable(User $auteur, Magasin $magasin): bool
    {
        if (! $auteur->peutAgir() || (int) $magasin->societe_id !== (int) $auteur->societe_id) {
            return false;
        }

        return $auteur->estProprietaire()
            || ($auteur->aLAutorisationDeSociete(AutorisationsSociete::RESPONSABLES_NOMMER) && $auteur->administreLeMagasin($magasin));
    }

    /**
     * Seul le propriétaire affecte une même personne à plusieurs magasins :
     * créer un compte avec plusieurs magasins, lui en ajouter un quand il
     * en a déjà un, le réintégrer dans plusieurs.
     */
    public function peutAffecterAPlusieursMagasins(User $auteur): bool
    {
        return $auteur->peutAgir() && $auteur->estProprietaire();
    }

    /**
     * Gère-t-il ce compte de vendeur ou d'observateur tout entier (ses
     * cases et son modèle dans chacun de ses magasins, ses gestes sur tout
     * le compte) ? Le propriétaire ; un responsable de chacun des magasins
     * d'un compte qui n'est que vendeur ou observateur.
     */
    public function gereLeCompte(User $auteur, User $compte): bool
    {
        if (! $this->estUneCible($auteur, $compte) || ! $compte->estMembre()) {
            return false;
        }

        if ($auteur->estProprietaire()) {
            return true;
        }

        $affectations = $compte->affectationsDeMembre();

        return $affectations->isNotEmpty()
            && $affectations->every(fn (Affectation $affectation): bool => $affectation->role !== RoleMagasin::Responsable
                && $affectation->magasin !== null
                && $this->gereLEquipeDe($auteur, $affectation->magasin));
    }

    /**
     * Peut-il donner ce rôle à ce compte dans ce magasin : créer son
     * affectation, ou changer celle qu'il y a (son rôle, ses cases) ? Avec
     * un compte nul, pour un compte qu'il crée ou qu'il réintègre. Le
     * nombre de magasins se contrôle à part (voir
     * peutAffecterAPlusieursMagasins()).
     */
    public function peutDonnerLeRole(User $auteur, ?User $compte, Magasin $magasin, RoleMagasin $role): bool
    {
        if (! $auteur->peutAgir() || (int) $magasin->societe_id !== (int) $auteur->societe_id) {
            return false;
        }

        if ($compte !== null && (! $this->estUneCible($auteur, $compte) || ! $compte->estMembre() || $compte->estRetire())) {
            return false;
        }

        $actuelle = $compte?->affectationsDeMembre()->firstWhere('magasin_id', $magasin->getKey());

        if ($compte !== null && ! $this->respecteLeProvisoire($auteur, $compte, $actuelle === null || ($role === RoleMagasin::Responsable && ! $actuelle->estDeResponsable()))) {
            return false;
        }

        if ($role === RoleMagasin::Responsable) {
            if (! $this->peutNommerUnResponsable($auteur, $magasin)) {
                return false;
            }

            return $auteur->estProprietaire()
                || $compte === null
                || $actuelle !== null
                || $this->nEstResponsableQueDansSaPortee($auteur, $compte);
        }

        if ($actuelle?->estDeResponsable()) {
            return $auteur->estProprietaire();
        }

        if (! $this->gereLEquipeDe($auteur, $magasin)) {
            return false;
        }

        return $compte === null || $auteur->estProprietaire() || $this->gereLeCompte($auteur, $compte);
    }

    /**
     * Peut-il retirer cette affectation de membre ? Selon son rôle : celle
     * d'un responsable, qui peut nommer un responsable dans ce magasin ;
     * celle d'un vendeur ou d'un observateur, qui gère ce compte tout
     * entier. La dernière affectation du compte, seulement qui peut le
     * retirer de la société (il l'est alors).
     *
     * @param  bool|null  $derniere  le compte n'aura plus aucune affectation après ce changement ; null : lu en base (ce retrait seul)
     */
    public function peutRetirerLAffectation(User $auteur, Affectation $affectation, ?bool $derniere = null): bool
    {
        $compte = User::query()->find($affectation->user_id);
        $magasin = $affectation->magasin;

        if ($compte === null || $magasin === null || $affectation->estDAdministrateur() || ! $this->estUneCible($auteur, $compte) || ! $compte->estMembre()) {
            return false;
        }

        if (($derniere ?? $compte->affectationsDeMembre()->count() === 1) && ! $this->peutRetirerDeLaSociete($auteur, $compte)) {
            return false;
        }

        if ($affectation->estDeResponsable()) {
            return $this->peutNommerUnResponsable($auteur, $magasin);
        }

        return $this->gereLeCompte($auteur, $compte);
    }

    /**
     * Peut-il suspendre ce compte, ou le réactiver s'il est suspendu ? Le
     * propriétaire ; un responsable de tous les magasins d'un vendeur ou
     * d'un observateur, sauf pour réactiver un compte que le propriétaire a
     * suspendu. Jamais un administrateur.
     */
    public function peutSuspendreOuReactiver(User $auteur, User $compte): bool
    {
        if (! $this->estUneCible($auteur, $compte) || $compte->estRetire()) {
            return false;
        }

        if ($auteur->estProprietaire()) {
            return true;
        }

        if ($compte->estSuspendu() && $this->estUneDecisionDuProprietaire($compte)) {
            return false;
        }

        return $this->gereLeCompte($auteur, $compte);
    }

    /**
     * Peut-il retirer ce compte de la société ? Le propriétaire ; un
     * responsable de tous les magasins d'un vendeur ou d'un observateur ;
     * un administrateur autorisé, pour une personne qui n'est que
     * responsable de magasins tous dans sa portée.
     */
    public function peutRetirerDeLaSociete(User $auteur, User $compte): bool
    {
        if (! $this->estUneCible($auteur, $compte) || $compte->estRetire()) {
            return false;
        }

        return $auteur->estProprietaire()
            || $this->gereLeCompte($auteur, $compte)
            || $this->nEstResponsableQueDansSaPortee($auteur, $compte);
    }

    /**
     * Peut-il renouveler l'accès de ce compte par un lien envoyé à la
     * personne ? Les mêmes que pour le retirer de la société.
     */
    public function peutRenouvelerLAcces(User $auteur, User $compte): bool
    {
        return $this->peutRetirerDeLaSociete($auteur, $compte);
    }

    /**
     * Peut-il lui donner un nouveau mot de passe provisoire ? Seulement si
     * son mot de passe est encore un provisoire de la société, par le
     * propriétaire, ou par un responsable de tous les magasins d'un vendeur
     * ou d'un observateur ; jamais par un administrateur.
     */
    public function peutDonnerUnProvisoire(User $auteur, User $compte): bool
    {
        if (! $this->estUneCible($auteur, $compte) || $compte->estRetire() || ! $compte->doitChangerSonMotDePasse()) {
            return false;
        }

        return $auteur->estProprietaire() || $this->gereLeCompte($auteur, $compte);
    }

    /**
     * Peut-il réintégrer ce compte retiré par la société, avec ces
     * affectations (magasin => rôle) ? Comme une création : le
     * propriétaire pour tout rôle ; un responsable, comme vendeur ou
     * observateur d'un de ses magasins ; un administrateur autorisé, comme
     * responsable d'un magasin de sa portée. Un compte retiré par le
     * propriétaire ne se réintègre que par lui, un compte retiré par son
     * propre refus jamais (seulement par un rattachement qu'il accepte), et
     * un compte au mot de passe provisoire que par la personne qui l'a créé.
     *
     * @param  array<int, RoleMagasin>  $roles  par identifiant de magasin
     */
    public function peutReintegrer(User $auteur, User $compte, array $roles): bool
    {
        if (! $auteur->peutAgir() || ! $compte->estRetire() || ! $compte->estMembre() || $roles === []
            || (int) $compte->societe_id !== (int) $auteur->societe_id || $compte->is($auteur)) {
            return false;
        }

        if ((int) $compte->statut_change_par === (int) $compte->getKey()) {
            return false;
        }

        if ($this->estUneDecisionDuProprietaire($compte) && ! $auteur->estProprietaire()) {
            return false;
        }

        if (! $this->respecteLeProvisoire($auteur, $compte, true) || (count($roles) > 1 && ! $this->peutAffecterAPlusieursMagasins($auteur))) {
            return false;
        }

        $magasins = Magasin::query()->where('societe_id', $auteur->societe_id)->whereKey(array_keys($roles))->get()->keyBy('id');

        foreach ($roles as $magasinId => $role) {
            $magasin = $magasins->get($magasinId);

            if ($magasin === null || ! $this->peutDonnerLeRole($auteur, null, $magasin, $role)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Peut-il annuler la nomination de ce responsable par un
     * administrateur ? Le propriétaire, qui en a été prévenu ; ou
     * l'administrateur qui l'a faite, s'il peut toujours nommer un
     * responsable dans ce magasin. Seulement tant que l'affectation est
     * celle de cette nomination.
     */
    public function peutAnnulerLaNomination(User $auteur, Affectation $affectation): bool
    {
        $magasin = $affectation->magasin;

        if ($affectation->nomme_par === null || ! $affectation->estDeResponsable() || $magasin === null || (int) $affectation->user_id === (int) $auteur->getKey()) {
            return false;
        }

        if (! $auteur->peutAgir() || (int) $magasin->societe_id !== (int) $auteur->societe_id) {
            return false;
        }

        return $auteur->estProprietaire()
            || ((int) $affectation->nomme_par === (int) $auteur->getKey() && $this->peutNommerUnResponsable($auteur, $magasin));
    }

    /**
     * Les autorisations qu'il peut cocher pour un vendeur ou un observateur
     * dans ce magasin : toutes pour le propriétaire et un responsable de ce
     * magasin ; aucune sinon (les cases d'un administrateur se cochent par
     * le propriétaire, dans les actions des administrateurs).
     *
     * @return list<string>
     */
    public function autorisationsCochables(User $auteur, Magasin $magasin): array
    {
        return $this->gereLEquipeDe($auteur, $magasin) ? PermissionRegistry::toutes() : [];
    }

    /**
     * Refuse une autorisation inconnue, puis une autorisation qu'il ne peut
     * pas cocher dans ce magasin.
     *
     * @param  array<array-key, mixed>  $autorisations
     * @return list<string> les autorisations connues, sans doublon, triées
     *
     * @throws LogicException
     */
    public function verifierCochables(User $auteur, Magasin $magasin, array $autorisations): array
    {
        $connues = PermissionRegistry::verifierConnues($autorisations);

        if (array_diff($connues, $this->autorisationsCochables($auteur, $magasin)) !== []) {
            throw new LogicException(self::REFUS_AU_DELA);
        }

        return $connues;
    }

    /**
     * Les comptes de sa société qu'il voit dans l'espace d'administration :
     * tous pour le propriétaire, experts-comptables compris ; pour un
     * administrateur autorisé à nommer les responsables, ceux qui ont une
     * affectation de responsable dans un magasin de sa portée ; aucun pour
     * tout autre compte.
     *
     * @return Builder<User>
     */
    public function comptesVisibles(User $auteur): Builder
    {
        $comptes = User::query()->where('users.societe_id', $auteur->societe_id);

        if (! $auteur->peutAgir()) {
            return $comptes->whereRaw('1 = 0');
        }

        if ($auteur->estProprietaire()) {
            return $comptes;
        }

        if (! $auteur->aLAutorisationDeSociete(AutorisationsSociete::RESPONSABLES_NOMMER)) {
            return $comptes->whereRaw('1 = 0');
        }

        return $comptes
            ->where('users.role_societe', RoleSociete::Membre->value)
            ->whereIn('users.id', Affectation::query()
                ->where('societe_id', $auteur->societe_id)
                ->where('role', RoleMagasin::Responsable->value)
                ->whereIn('magasin_id', $auteur->magasinsDeSaPortee()->select('magasins.id'))
                ->select('user_id'));
    }

    public function voit(User $auteur, User $compte): bool
    {
        return (int) $compte->societe_id === (int) $auteur->societe_id
            && $this->comptesVisibles($auteur)->whereKey($compte->getKey())->exists();
    }

    /**
     * Les comptes qu'il peut nommer responsables de ce magasin : des
     * membres actifs, qui n'en sont pas déjà responsables, ont choisi leur
     * mot de passe ou ont été créés par lui ; pour le propriétaire, tout
     * membre de la société ; pour un administrateur autorisé, un vendeur
     * ou un observateur de ce magasin, sans nouveau magasin. Jamais un
     * administrateur, un expert-comptable ni le propriétaire.
     *
     * @return Builder<User>
     */
    public function candidatsResponsables(User $auteur, Magasin $magasin): Builder
    {
        $candidats = User::query()
            ->where('users.societe_id', $magasin->societe_id)
            ->where('users.role_societe', RoleSociete::Membre->value)
            ->where('users.statut', StatutCompte::Actif->value)
            ->whereKeyNot($auteur->getKey())
            ->where(fn (Builder $comptes) => $comptes->where('users.mot_de_passe_a_changer', false)->orWhere('users.cree_par', $auteur->getKey()))
            ->whereNotIn('users.id', Affectation::query()->where('magasin_id', $magasin->getKey())->where('role', RoleMagasin::Responsable->value)->select('user_id'));

        if (! $this->peutNommerUnResponsable($auteur, $magasin)) {
            return $candidats->whereRaw('1 = 0');
        }

        if ($auteur->estProprietaire()) {
            return $candidats;
        }

        return $candidats->whereIn('users.id', Affectation::query()
            ->where('magasin_id', $magasin->getKey())
            ->whereIn('role', [RoleMagasin::Vendeur->value, RoleMagasin::Observateur->value])
            ->select('user_id'));
    }

    /**
     * Peut-il proposer ce rôle et ces affectations dans sa société, par un
     * rattachement ? Comme une création : comme expert-comptable, le
     * propriétaire seul, sans affectation ; comme membre, au moins un
     * magasin, plusieurs par le propriétaire seul, et dans chacun le rôle
     * qu'il peut donner (vendeur ou observateur par le propriétaire ou un
     * responsable du magasin, responsable par le propriétaire ou un
     * administrateur autorisé dans sa portée).
     *
     * @param  array<int, RoleMagasin>  $roles  par identifiant de magasin
     */
    public function peutProposerUnRattachement(User $auteur, RoleSociete $role, array $roles): bool
    {
        if (! $auteur->peutAgir()) {
            return false;
        }

        if ($role === RoleSociete::ExpertComptable) {
            return $auteur->estProprietaire() && $roles === [];
        }

        if ($role !== RoleSociete::Membre || $roles === [] || (count($roles) > 1 && ! $this->peutAffecterAPlusieursMagasins($auteur))) {
            return false;
        }

        $magasins = Magasin::query()->where('societe_id', $auteur->societe_id)->whereKey(array_keys($roles))->get()->keyBy('id');

        foreach ($roles as $magasinId => $roleDeMagasin) {
            $magasin = $magasins->get($magasinId);

            if ($magasin === null || ! $this->peutDonnerLeRole($auteur, null, $magasin, $roleDeMagasin)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Ce compte peut-il recevoir de l'auteur une proposition de
     * rattachement à la société de l'auteur ? Seulement un compte retiré,
     * membre (un administrateur ou un expert-comptable retiré l'est
     * redevenu) ; retiré par le propriétaire de cette même société, par lui
     * seul. Un compte encore actif ou suspendu, dans cette société ou une
     * autre, jamais : il doit d'abord en être retiré.
     */
    public function peutRecevoirUnRattachement(User $auteur, User $compte): bool
    {
        if (! $compte->estRetire() || ! $compte->estMembre() || $compte->is($auteur)) {
            return false;
        }

        return (int) $compte->societe_id !== (int) $auteur->societe_id
            || (int) $compte->statut_change_par === (int) $compte->getKey()
            || ! $this->estUneDecisionDuProprietaire($compte)
            || $auteur->estProprietaire();
    }

    /**
     * Ce membre de l'équipe du magasin travaille-t-il aussi dans un autre
     * magasin ? Lu seulement pour savoir qui peut gérer son compte et le
     * dire à l'écran, sans jamais dire lequel (une lecture hors du magasin,
     * déclarée dans CLAUDE.md).
     */
    public function aAussiDAutresMagasins(User $compte, Magasin $magasin): bool
    {
        return Affectation::query()
            ->where('societe_id', $magasin->societe_id)
            ->where('user_id', $compte->getKey())
            ->whereNotNull('role')
            ->where('magasin_id', '<>', $magasin->getKey())
            ->exists();
    }

    /**
     * Une cible possible d'un geste : un compte de sa société, autre que le
     * sien et que celui du propriétaire, par un compte qui peut agir.
     */
    private function estUneCible(User $auteur, User $compte): bool
    {
        return $auteur->peutAgir()
            && (int) $compte->societe_id === (int) $auteur->societe_id
            && ! $compte->is($auteur)
            && ! $compte->estProprietaire();
    }

    /**
     * Une personne qui n'est que responsable, de magasins tous dans la
     * portée d'un administrateur autorisé à nommer les responsables.
     */
    private function nEstResponsableQueDansSaPortee(User $auteur, User $compte): bool
    {
        if (! $auteur->estAdministrateur() || ! $compte->estMembre() || ! $this->estUneCible($auteur, $compte)) {
            return false;
        }

        $affectations = $compte->affectationsDeMembre();

        return $affectations->isNotEmpty()
            && $affectations->every(fn (Affectation $affectation): bool => $affectation->estDeResponsable()
                && $affectation->magasin !== null
                && $this->peutNommerUnResponsable($auteur, $affectation->magasin));
    }

    /**
     * Le statut du compte a-t-il été donné par le propriétaire de la
     * société ?
     */
    private function estUneDecisionDuProprietaire(User $compte): bool
    {
        return $compte->statut_change_par !== null
            && User::query()->whereKey($compte->statut_change_par)->where('role_societe', RoleSociete::Proprietaire->value)->exists();
    }

    /**
     * Un compte au mot de passe provisoire ne reçoit de nouveaux droits
     * (un nouveau magasin, un rôle de responsable, une réintégration) que
     * de la personne qui l'a créé.
     */
    private function respecteLeProvisoire(User $auteur, User $compte, bool $nouveauxDroits): bool
    {
        return ! $nouveauxDroits || ! $compte->doitChangerSonMotDePasse() || (int) $compte->cree_par === (int) $auteur->getKey();
    }
}
