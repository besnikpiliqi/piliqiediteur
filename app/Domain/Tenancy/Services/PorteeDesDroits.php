<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\StatutSociete;
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
 *   compte (ses droits, son rôle, son statut), ni sur le propriétaire ;
 *   aucun dans une société révoquée (voir gereSonEquipe()) ;
 * - un compte de membre ne vaut que pour un magasin, celui de son
 *   identifiant (voir User::magasinDeSonIdentifiant()) : une personne qui
 *   travaille dans plusieurs magasins y a un compte dans chacun, et aucun
 *   geste ne fait changer un compte de magasin ;
 * - seul le propriétaire gère les administrateurs et les
 *   experts-comptables, et change le rôle de société d'un compte ;
 * - responsable d'un magasin : nommé ou retiré par le propriétaire, ou par
 *   un administrateur autorisé à nommer les responsables, dans les
 *   magasins réels de sa portée (voir peutNommerUnResponsable()), jamais
 *   dans le magasin de démonstration ; rétrogradé, suspendu ou réactivé par
 *   le propriétaire seul ; jamais touché par un autre responsable ;
 * - vendeur ou observateur : créé, coché, modifié, suspendu, réactivé et
 *   retiré par le propriétaire ou un responsable de son magasin (voir
 *   gereLeCompte()) ;
 * - accès redonné (voir peutRedonnerLAcces()) : par le propriétaire pour
 *   tout compte ; par un responsable de son magasin pour un vendeur ou un
 *   observateur ; par un administrateur autorisé à nommer les responsables
 *   pour un responsable d'un magasin réel de sa portée, et par un
 *   administrateur autorisé à redonner l'accès pour un vendeur ou un
 *   observateur d'un magasin réel de sa portée ; jamais pour le
 *   propriétaire, un administrateur ou un expert-comptable par un autre que
 *   le propriétaire ;
 * - un compte suspendu par le propriétaire ne se réactive, ni ne se retire
 *   de la société, que par le propriétaire ; retiré par lui, il ne se
 *   réintègre que par le propriétaire. La décision se lit sur le compte,
 *   telle qu'elle a été prise (voir User::statutDecideParLeProprietaire()) :
 *   elle tient après une transmission de la propriété ;
 * - ce qu'on coche pour quelqu'un dans un magasin : toutes les
 *   autorisations pour le propriétaire, y compris celles de la vente qu'il
 *   n'exerce pas lui-même, toutes celles de son magasin pour un
 *   responsable, qui les a toutes, aucune pour tout autre compte (voir
 *   autorisationsCochables()) ;
 * - l'identifiant de connexion d'un compte ne se montre qu'à la personne,
 *   au propriétaire et, pour un vendeur ou un observateur, au responsable
 *   de son magasin (voir voitLIdentifiant()) ; à un administrateur,
 *   seulement par un geste tracé (voir peutAfficherLIdentifiant()).
 *
 * Retirer un compte de son magasin le retire de la société : n'en a le
 * droit que qui peut le retirer de la société.
 */
final class PorteeDesDroits
{
    public const REFUS = 'Vous ne pouvez pas faire cela pour ce compte.';

    public const REFUS_AU_DELA = 'Vous ne pouvez pas donner, dans ce magasin, une autorisation que vous n\'avez pas.';

    public const REFUS_AUTRE_MAGASIN = 'Un compte ne vaut que pour son magasin : créez-lui un compte dans cet autre magasin.';

    /**
     * Peut-il gérer l'équipe de sa société, dans la mesure de son rôle ? Un
     * compte qui peut agir, dans une société qui n'est pas révoquée : celle
     * d'un usurpateur garde son équipe consultable, mais plus rien ne s'y
     * gère ; une société suspendue, résiliée ou close, ou un magasin
     * fermé, la gère encore (un accès se coupe toujours).
     */
    public function gereSonEquipe(User $auteur): bool
    {
        return $auteur->peutAgir() && $auteur->societe()->where('statut', StatutSociete::Revoquee->value)->doesntExist();
    }

    /**
     * L'équipe de ce magasin (ses vendeurs et ses observateurs) est-elle la
     * sienne ? Le propriétaire de la société du magasin, ou un responsable
     * de ce magasin, avec un compte qui peut agir : il la voit, et la gère
     * tant que sa société n'est pas révoquée (voir gereSonEquipe(), que
     * chaque geste exige en plus).
     */
    public function gereLEquipeDe(User $auteur, Magasin $magasin): bool
    {
        return $auteur->peutAgir()
            && (int) $magasin->societe_id === (int) $auteur->societe_id
            && ($auteur->estProprietaire() || $auteur->estResponsableDe($magasin));
    }

    /**
     * Peut-il nommer ou retirer un responsable dans ce magasin, en créer le
     * compte et lui redonner l'accès ? Le propriétaire ; un administrateur
     * autorisé à nommer les responsables, pour un magasin réel de sa
     * portée, jamais le magasin de démonstration, dont seuls le
     * propriétaire et ses responsables créent les comptes d'entraînement.
     */
    public function peutNommerUnResponsable(User $auteur, Magasin $magasin): bool
    {
        if (! $this->gereSonEquipe($auteur) || (int) $magasin->societe_id !== (int) $auteur->societe_id) {
            return false;
        }

        return $auteur->estProprietaire()
            || (! $magasin->est_demonstration && $auteur->aLAutorisationDeSociete(AutorisationsSociete::RESPONSABLES_NOMMER) && $auteur->administreLeMagasin($magasin));
    }

    /**
     * Gère-t-il ce compte de vendeur ou d'observateur tout entier (ses
     * cases et son modèle, ses gestes sur tout le compte) ? Le
     * propriétaire ; un responsable du magasin du compte.
     */
    public function gereLeCompte(User $auteur, User $compte): bool
    {
        if (! $this->estUneCible($auteur, $compte) || ! $compte->estMembre()) {
            return false;
        }

        if ($auteur->estProprietaire()) {
            return true;
        }

        $affectation = $compte->affectationDeMembre();

        return $affectation !== null
            && $affectation->role !== RoleMagasin::Responsable
            && $affectation->magasin !== null
            && $this->gereLEquipeDe($auteur, $affectation->magasin);
    }

    /**
     * Peut-il donner ce rôle à ce compte dans ce magasin : créer son
     * affectation, ou changer celle qu'il y a (son rôle, ses cases) ? Avec
     * un compte nul, pour un compte qu'il crée ou qu'il réintègre. Un
     * compte existant ne reçoit de rôle que dans le magasin de son
     * identifiant. Jamais dans le magasin de démonstration d'une société
     * validée ou close, dont les comptes d'entraînement sont retirés pour
     * de bon (voir Magasin::estUneDemonstrationTerminee()).
     */
    public function peutDonnerLeRole(User $auteur, ?User $compte, Magasin $magasin, RoleMagasin $role): bool
    {
        if (! $this->gereSonEquipe($auteur) || (int) $magasin->societe_id !== (int) $auteur->societe_id || $magasin->estUneDemonstrationTerminee()) {
            return false;
        }

        if ($compte !== null && (! $this->estUneCible($auteur, $compte) || ! $compte->estMembre() || $compte->estRetire()
            || (int) $compte->magasinDeSonIdentifiant()?->getKey() !== (int) $magasin->getKey())) {
            return false;
        }

        $actuelle = $compte?->affectationDeMembre();

        if ($role === RoleMagasin::Responsable) {
            return $this->peutNommerUnResponsable($auteur, $magasin);
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
     * Peut-il retirer cette affectation de membre ? C'est le seul magasin du
     * compte : la retirer le retire de la société, ce que seul peut faire
     * qui peut le retirer de la société (voir peutRetirerDeLaSociete()).
     */
    public function peutRetirerLAffectation(User $auteur, Affectation $affectation): bool
    {
        $compte = User::query()->find($affectation->user_id);

        return $compte !== null
            && ! $affectation->estDAdministrateur()
            && $compte->estMembre()
            && $this->peutRetirerDeLaSociete($auteur, $compte);
    }

    /**
     * Peut-il suspendre ce compte, ou le réactiver s'il est suspendu ? Le
     * propriétaire ; un responsable du magasin d'un vendeur ou d'un
     * observateur, sauf pour réactiver un compte que le propriétaire a
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

        return ! $this->estSuspenduParLeProprietaire($compte) && $this->gereLeCompte($auteur, $compte);
    }

    /**
     * Peut-il retirer ce compte de la société ? Le propriétaire ; un
     * responsable du magasin d'un vendeur ou d'un observateur ; un
     * administrateur autorisé à nommer les responsables, pour un
     * responsable d'un magasin réel de sa portée. Un compte suspendu par le
     * propriétaire, le propriétaire seul : retiré par un autre, puis
     * réintégré par lui, il verrait sa suspension levée sans le
     * propriétaire.
     */
    public function peutRetirerDeLaSociete(User $auteur, User $compte): bool
    {
        if (! $this->estUneCible($auteur, $compte) || $compte->estRetire()) {
            return false;
        }

        if ($auteur->estProprietaire()) {
            return true;
        }

        return ! $this->estSuspenduParLeProprietaire($compte)
            && ($this->gereLeCompte($auteur, $compte) || $this->estResponsableDeSaPortee($auteur, $compte));
    }

    /**
     * Peut-il redonner l'accès à ce compte actif (voir
     * RedonnerLAccesAction) ? Le propriétaire, pour tout compte ; un
     * responsable du magasin d'un vendeur ou d'un observateur ; un
     * administrateur autorisé à nommer les responsables, pour un
     * responsable d'un magasin réel de sa portée ; un administrateur que le
     * propriétaire autorise à redonner l'accès, pour un vendeur ou un
     * observateur d'un magasin réel de sa portée. Personne d'autre que le
     * propriétaire ne redonne l'accès d'un administrateur ou d'un
     * expert-comptable, et personne celui du propriétaire. Un compte
     * suspendu se réactive d'abord, par qui le peut.
     */
    public function peutRedonnerLAcces(User $auteur, User $compte): bool
    {
        if (! $this->estUneCible($auteur, $compte) || ! $compte->estActif()) {
            return false;
        }

        if ($auteur->estProprietaire()) {
            return true;
        }

        $affectation = $compte->affectationDeMembre();

        if ($affectation === null || $affectation->magasin === null) {
            return false;
        }

        if ($affectation->estDeResponsable()) {
            return $this->peutNommerUnResponsable($auteur, $affectation->magasin);
        }

        return $this->gereLEquipeDe($auteur, $affectation->magasin)
            || $this->redonneLAccesDesVendeursDe($auteur, $affectation->magasin);
    }

    /**
     * Voit-il l'identifiant de connexion de ce compte, dans les listes et
     * les fiches ? La personne elle-même ; le propriétaire, pour tout
     * compte de sa société ; un responsable, pour un vendeur ou un
     * observateur de son magasin, dont il gère le compte. Jamais un
     * administrateur : il ne le voit que par un geste tracé (voir
     * peutAfficherLIdentifiant()).
     */
    public function voitLIdentifiant(User $auteur, User $compte): bool
    {
        if ((int) $compte->societe_id !== (int) $auteur->societe_id || ! $auteur->peutAgir()) {
            return false;
        }

        return $compte->is($auteur) || $auteur->estProprietaire() || ($auteur->estMembre() && $this->gereLeCompte($auteur, $compte));
    }

    /**
     * Peut-il faire « Afficher l'identifiant », geste inscrit au journal
     * d'audit et signalé au propriétaire quand le mot de passe du compte
     * est encore son identifiant (voir AfficherLIdentifiantAction) ? Un
     * administrateur qui peut redonner l'accès à ce compte.
     */
    public function peutAfficherLIdentifiant(User $auteur, User $compte): bool
    {
        return $auteur->estAdministrateur() && $this->peutRedonnerLAcces($auteur, $compte);
    }

    /**
     * Peut-il réintégrer ce compte de membre retiré, avec ce rôle, dans le
     * magasin de son identifiant ? Comme une création : le propriétaire
     * pour tout rôle ; un responsable de ce magasin, comme vendeur ou
     * observateur ; un administrateur autorisé, comme responsable d'un
     * magasin réel de sa portée. Un compte retiré par le propriétaire ne se
     * réintègre que par lui.
     */
    public function peutReintegrer(User $auteur, User $compte, RoleMagasin $role): bool
    {
        if (! $this->gereSonEquipe($auteur) || ! $compte->estRetire() || ! $compte->estMembre()
            || (int) $compte->societe_id !== (int) $auteur->societe_id || $compte->is($auteur)) {
            return false;
        }

        if ($compte->statutDecideParLeProprietaire() && ! $auteur->estProprietaire()) {
            return false;
        }

        $magasin = $compte->magasinDeSonIdentifiant();

        return $magasin !== null && $this->peutDonnerLeRole($auteur, null, $magasin, $role);
    }

    /**
     * Peut-il changer le rôle de société de ce compte (membre,
     * administrateur, expert-comptable ; voir
     * ChangerLeRoleDeSocieteAction) ? Le propriétaire seul, pour un compte
     * actif de sa société qui n'est pas un compte d'entraînement.
     */
    public function peutChangerLeRoleDeSociete(User $auteur, User $compte): bool
    {
        return $this->estUneCible($auteur, $compte)
            && $auteur->estProprietaire()
            && $compte->estActif()
            && ! $compte->estUnCompteDEntrainement();
    }

    /**
     * Peut-il annuler la nomination de ce responsable par un
     * administrateur ? Le propriétaire, qui en a été prévenu ; ou
     * l'administrateur qui l'a faite, s'il peut toujours nommer un
     * responsable dans ce magasin. Seulement tant que l'affectation est
     * celle de cette nomination. Une annulation qui retirerait la personne
     * de la société (la nomination a créé son compte), seulement qui peut
     * l'en retirer.
     */
    public function peutAnnulerLaNomination(User $auteur, Affectation $affectation): bool
    {
        $magasin = $affectation->magasin;
        $compte = User::query()->find($affectation->user_id);

        if ($compte === null || $affectation->nomme_par === null || ! $affectation->estDeResponsable() || $magasin === null || $compte->is($auteur)) {
            return false;
        }

        if (! $this->gereSonEquipe($auteur) || (int) $magasin->societe_id !== (int) $auteur->societe_id) {
            return false;
        }

        if ($affectation->avant_la_nomination === null && ! $this->peutRetirerDeLaSociete($auteur, $compte)) {
            return false;
        }

        return $auteur->estProprietaire()
            || ((int) $affectation->nomme_par === (int) $auteur->getKey() && $this->peutNommerUnResponsable($auteur, $magasin));
    }

    /**
     * Les autorisations qu'il peut cocher pour un vendeur ou un observateur
     * dans ce magasin : toutes pour le propriétaire, celles de la vente
     * comprises, qu'il ne fait jamais lui-même mais coche pour qui il veut,
     * et pour un responsable de ce magasin, qui les a toutes ; aucune sinon
     * (les cases d'un administrateur se cochent par le propriétaire, dans
     * les actions des administrateurs).
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
     * administrateur, les responsables des magasins réels de sa portée s'il
     * est autorisé à nommer les responsables, et leurs vendeurs et leurs
     * observateurs s'il est autorisé à leur redonner l'accès ; aucun pour
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

        $roles = [
            ...($auteur->aLAutorisationDeSociete(AutorisationsSociete::RESPONSABLES_NOMMER) ? [RoleMagasin::Responsable->value] : []),
            ...($auteur->aLAutorisationDeSociete(AutorisationsSociete::ACCES_REDONNER) ? [RoleMagasin::Vendeur->value, RoleMagasin::Observateur->value] : []),
        ];

        if ($roles === []) {
            return $comptes->whereRaw('1 = 0');
        }

        return $comptes
            ->where('users.role_societe', RoleSociete::Membre->value)
            ->whereIn('users.id', Affectation::query()
                ->where('societe_id', $auteur->societe_id)
                ->whereIn('role', $roles)
                ->whereIn('magasin_id', $auteur->magasinsDeSaPortee()->where('magasins.est_demonstration', false)->select('magasins.id'))
                ->select('user_id'));
    }

    public function voit(User $auteur, User $compte): bool
    {
        return (int) $compte->societe_id === (int) $auteur->societe_id
            && $this->comptesVisibles($auteur)->whereKey($compte->getKey())->exists();
    }

    /**
     * Les comptes qu'il peut nommer responsables de ce magasin : les
     * vendeurs et les observateurs actifs de ce magasin, jamais un autre
     * compte que le sien, ni rien quand il ne peut pas y nommer un
     * responsable (un administrateur dans le magasin de démonstration, par
     * exemple). Un compte dont le mot de passe est encore son identifiant
     * se propose, la fenêtre l'avertissant.
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
            ->whereIn('users.id', Affectation::query()
                ->where('magasin_id', $magasin->getKey())
                ->whereIn('role', [RoleMagasin::Vendeur->value, RoleMagasin::Observateur->value])
                ->select('user_id'));

        return $this->peutNommerUnResponsable($auteur, $magasin) ? $candidats : $candidats->whereRaw('1 = 0');
    }

    /**
     * Une cible possible d'un geste : un compte de sa société, autre que le
     * sien et que celui du propriétaire, par un compte qui gère son équipe.
     */
    private function estUneCible(User $auteur, User $compte): bool
    {
        return $this->gereSonEquipe($auteur)
            && (int) $compte->societe_id === (int) $auteur->societe_id
            && ! $compte->is($auteur)
            && ! $compte->estProprietaire();
    }

    /**
     * Le compte est-il suspendu, par une décision du propriétaire ?
     */
    private function estSuspenduParLeProprietaire(User $compte): bool
    {
        return $compte->estSuspendu() && $compte->statutDecideParLeProprietaire();
    }

    /**
     * Le compte est-il celui d'un responsable d'un magasin réel de la
     * portée d'un administrateur autorisé à nommer les responsables ?
     */
    private function estResponsableDeSaPortee(User $auteur, User $compte): bool
    {
        if (! $auteur->estAdministrateur() || ! $compte->estMembre()) {
            return false;
        }

        $affectation = $compte->affectationDeMembre();

        return $affectation !== null
            && $affectation->estDeResponsable()
            && $affectation->magasin !== null
            && $this->peutNommerUnResponsable($auteur, $affectation->magasin);
    }

    /**
     * Est-il un administrateur que le propriétaire autorise à redonner
     * l'accès aux vendeurs et aux observateurs, pour ce magasin réel de sa
     * portée ?
     */
    private function redonneLAccesDesVendeursDe(User $auteur, Magasin $magasin): bool
    {
        return $auteur->estAdministrateur()
            && ! $magasin->est_demonstration
            && $auteur->aLAutorisationDeSociete(AutorisationsSociete::ACCES_REDONNER)
            && $auteur->administreLeMagasin($magasin);
    }
}
