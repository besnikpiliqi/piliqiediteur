<?php

namespace App\Policies;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Models\User;

/**
 * Les gestes sur les comptes de la société (voir MembreResource, dans son
 * espace d'administration, et EquipeDuMagasinResource, dans le panneau
 * d'un magasin), selon les règles de PorteeDesDroits :
 * - la liste de l'espace d'administration : tous les comptes pour le
 *   propriétaire ; les responsables de ses magasins pour un administrateur
 *   autorisé à nommer les responsables ; rien pour tout autre compte ;
 * - les administrateurs et les experts-comptables se créent, se nomment
 *   et se modifient par le propriétaire seul ;
 * - suspendre, réactiver, retirer de la société, renouveler l'accès,
 *   redonner un mot de passe provisoire : qui en a le droit pour ce compte ;
 * - personne ne touche à son propre compte, ni au propriétaire.
 * Un compte ne se supprime jamais.
 *
 * Dans une société ou un magasin en lecture seule, l'équipe reste gérable,
 * sauf dans une société révoquée, où seule la consultation reste (voir
 * ControleDesDroits). Les actions du domaine posent elles-mêmes ces
 * questions, et revérifient sous leurs verrous sur des comptes relus.
 */
class MembrePolicy
{
    public function __construct(private readonly PorteeDesDroits $portee) {}

    public function viewAny(User $user): bool
    {
        return $user->peutAgir() && ($user->estProprietaire() || $user->aLAutorisationDeSociete(AutorisationsSociete::RESPONSABLES_NOMMER));
    }

    public function view(User $user, User $membre): bool
    {
        return $this->viewAny($user) && $this->portee->voit($user, $membre);
    }

    /**
     * Créer un compte : le propriétaire (tout rôle), ou un administrateur
     * autorisé à nommer les responsables (un responsable d'un magasin de sa
     * portée).
     */
    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    /**
     * Ouvrir la fiche d'un compte pour la gérer : un compte qu'il voit,
     * jamais le sien ni celui du propriétaire.
     */
    public function update(User $user, User $membre): bool
    {
        return $this->view($user, $membre) && ! $membre->is($user) && ! $membre->estProprietaire();
    }

    public function suspendreOuReactiver(User $user, User $membre): bool
    {
        return $this->portee->peutSuspendreOuReactiver($user, $membre);
    }

    public function retirerDeLaSociete(User $user, User $membre): bool
    {
        return $this->portee->peutRetirerDeLaSociete($user, $membre);
    }

    public function renouvelerLAcces(User $user, User $membre): bool
    {
        return $this->portee->peutRenouvelerLAcces($user, $membre);
    }

    public function donnerUnProvisoire(User $user, User $membre): bool
    {
        return $this->portee->peutDonnerUnProvisoire($user, $membre);
    }

    /**
     * Créer un administrateur, ou nommer administrateur un membre : le
     * propriétaire seul, jamais pour un expert-comptable.
     */
    public function creerUnAdministrateur(User $user): bool
    {
        return $user->peutAgir() && $user->estProprietaire();
    }

    public function nommerAdministrateur(User $user, User $membre): bool
    {
        return $this->creerUnAdministrateur($user)
            && $membre->estMembre()
            && ! $membre->estRetire()
            && (int) $membre->societe_id === (int) $user->societe_id;
    }

    /**
     * Modifier, rétrograder un administrateur : le propriétaire seul.
     */
    public function modifierUnAdministrateur(User $user, User $administrateur): bool
    {
        return $this->creerUnAdministrateur($user)
            && $administrateur->estAdministrateur()
            && (int) $administrateur->societe_id === (int) $user->societe_id;
    }

    /**
     * Transmettre la propriété à cet administrateur actif de sa société
     * (voir TransfererProprieteAction, qui exige en plus sa qualité de
     * représentant prouvée et le mot de passe du propriétaire) : le
     * propriétaire seul, jamais à un expert-comptable ni à un membre. Un
     * geste sur l'équipe : permis dans une société suspendue, jamais dans
     * une société révoquée (voir ControleDesDroits).
     */
    public function transmettreLaPropriete(User $user, User $administrateur): bool
    {
        return $this->creerUnAdministrateur($user)
            && $administrateur->estAdministrateur()
            && $administrateur->peutAgir()
            && (int) $administrateur->societe_id === (int) $user->societe_id;
    }

    /**
     * Créer un expert-comptable : le propriétaire seul.
     */
    public function creerLExpertComptable(User $user): bool
    {
        return $this->creerUnAdministrateur($user);
    }

    public function delete(User $user, User $membre): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, User $membre): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, User $membre): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, User $membre): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
