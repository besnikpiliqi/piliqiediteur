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
 *   propriétaire ; pour un administrateur, les responsables des magasins
 *   réels de sa portée s'il est autorisé à nommer les responsables, et
 *   leurs vendeurs et leurs observateurs s'il est autorisé à leur redonner
 *   l'accès ; rien pour tout autre compte ;
 * - créer un compte : le propriétaire, ou un administrateur autorisé à
 *   nommer les responsables (le compte d'un responsable) ; jamais un
 *   administrateur qui n'est autorisé qu'à redonner l'accès ;
 * - les administrateurs et les experts-comptables se créent et se
 *   modifient par le propriétaire seul, qui seul change le rôle de société
 *   d'un compte et transmet la propriété ;
 * - suspendre, réactiver, retirer de la société, redonner l'accès,
 *   afficher l'identifiant : qui en a le droit pour ce compte ;
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
        return $user->peutAgir() && ($user->estProprietaire()
            || $user->aLAutorisationDeSociete(AutorisationsSociete::RESPONSABLES_NOMMER)
            || $user->aLAutorisationDeSociete(AutorisationsSociete::ACCES_REDONNER));
    }

    public function view(User $user, User $membre): bool
    {
        return $this->viewAny($user) && $this->portee->voit($user, $membre);
    }

    /**
     * Créer un compte : le propriétaire (tout rôle), ou un administrateur
     * autorisé à nommer les responsables (le compte d'un responsable d'un
     * magasin réel de sa portée ; voir PorteeDesDroits::peutDonnerLeRole()).
     */
    public function create(User $user): bool
    {
        return $user->peutAgir() && ($user->estProprietaire() || $user->aLAutorisationDeSociete(AutorisationsSociete::RESPONSABLES_NOMMER));
    }

    /**
     * Gérer ce compte au-delà de son accès : un compte qu'il voit, jamais
     * le sien ni celui du propriétaire ; pour un administrateur, seulement
     * le compte d'un responsable d'un magasin réel de sa portée, s'il est
     * autorisé à nommer les responsables. Un administrateur qui n'est
     * autorisé qu'à redonner l'accès d'un vendeur ou d'un observateur n'a
     * sur sa fiche que « Redonner l'accès » et « Afficher l'identifiant ».
     */
    public function update(User $user, User $membre): bool
    {
        if (! $this->view($user, $membre) || $membre->is($user) || $membre->estProprietaire()) {
            return false;
        }

        if ($user->estProprietaire()) {
            return true;
        }

        $affectation = $membre->affectationDeMembre();

        return $affectation !== null
            && $affectation->estDeResponsable()
            && $affectation->magasin !== null
            && $this->portee->peutNommerUnResponsable($user, $affectation->magasin);
    }

    public function suspendreOuReactiver(User $user, User $membre): bool
    {
        return $this->portee->peutSuspendreOuReactiver($user, $membre);
    }

    public function retirerDeLaSociete(User $user, User $membre): bool
    {
        return $this->portee->peutRetirerDeLaSociete($user, $membre);
    }

    public function redonnerLAcces(User $user, User $membre): bool
    {
        return $this->portee->peutRedonnerLAcces($user, $membre);
    }

    public function voirLIdentifiant(User $user, User $membre): bool
    {
        return $this->portee->voitLIdentifiant($user, $membre);
    }

    public function afficherLIdentifiant(User $user, User $membre): bool
    {
        return $this->portee->peutAfficherLIdentifiant($user, $membre);
    }

    /**
     * Changer le rôle de société de ce compte (membre, administrateur,
     * expert-comptable) : le propriétaire seul, pour un compte actif qui
     * n'est pas un compte d'entraînement.
     */
    public function changerLeRoleDeSociete(User $user, User $membre): bool
    {
        return $this->portee->peutChangerLeRoleDeSociete($user, $membre);
    }

    /**
     * Créer un administrateur : le propriétaire seul.
     */
    public function creerUnAdministrateur(User $user): bool
    {
        return $user->peutAgir() && $user->estProprietaire();
    }

    /**
     * Modifier la portée et les autorisations d'un administrateur, ou
     * réintégrer un administrateur retiré : le propriétaire seul.
     */
    public function modifierUnAdministrateur(User $user, User $administrateur): bool
    {
        return $this->creerUnAdministrateur($user)
            && $this->portee->gereSonEquipe($user)
            && $administrateur->estAdministrateur()
            && (int) $administrateur->societe_id === (int) $user->societe_id;
    }

    /**
     * Demander, renvoyer ou annuler la transmission de la propriété à cet
     * administrateur actif de sa société (voir
     * DemanderLaTransmissionDeLaProprieteAction, qui exige en plus son
     * propre mot de passe, son adresse renseignée par lui et le mot de
     * passe du propriétaire, sans aucune preuve de qualité de
     * représentant) : le propriétaire seul, jamais à un expert-comptable ni
     * à un membre. Un geste sur l'équipe : permis dans une société
     * suspendue, jamais dans une société révoquée (voir ControleDesDroits).
     */
    public function transmettreLaPropriete(User $user, User $administrateur): bool
    {
        return $this->creerUnAdministrateur($user)
            && $administrateur->estAdministrateur()
            && $administrateur->estActif()
            && (int) $administrateur->societe_id === (int) $user->societe_id;
    }

    /**
     * Créer ou réintégrer un expert-comptable : le propriétaire seul.
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
