<?php

namespace App\Policies;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Models\User;

/**
 * Les magasins de la société, vus de son espace d'administration : le
 * propriétaire et ses administrateurs ne lisent que ceux de leur portée
 * (voir User::magasinsDeSaPortee()), jamais ceux d'une autre société. En
 * créer un, c'est la règle de SocietePolicy::creerUnMagasin() dans sa
 * société (voir create()). Les gestes sur un magasin réel de sa portée
 * (le modifier, changer son SIRET, le fermer, le rouvrir) reviennent à qui
 * gère les magasins de la société (voir SocietePolicy::gererLesMagasins() :
 * le propriétaire, ou un administrateur autorisé, tant que la société
 * n'est pas en lecture seule) ; le céder, au propriétaire seul ; indiquer
 * l'établissement principal, à qui a tous les magasins dans sa portée.
 * Jamais un responsable, un vendeur, un observateur ni un
 * expert-comptable, qui n'ont pas d'espace d'administration ; jamais le
 * magasin de démonstration, qui s'ouvre et se ferme à part (voir
 * OuvrirLaDemonstrationAction et FermerLaDemonstrationAction). Ce qu'un
 * geste exige de l'état du magasin (actif, fermé, cédé...), son action le
 * vérifie sous ses verrous, avec son propre message. Nommer un
 * responsable dans un magasin passe par nommerUnResponsable().
 *
 * Les magasins ne s'ouvrent pas par cette policy dans le panneau des
 * magasins : Filament y demande User::canAccessTenant().
 */
class MagasinDeLaSocietePolicy
{
    public function __construct(
        private readonly PorteeDesDroits $portee,
        private readonly SocietePolicy $societes,
    ) {}

    public function viewAny(User $user): bool
    {
        return $user->peutAdministrer() && $user->peutAgir();
    }

    public function view(User $user, Magasin $magasin): bool
    {
        return $this->viewAny($user) && $user->administreLeMagasin($magasin);
    }

    /**
     * Nommer, retirer ou déplacer un responsable dans ce magasin : le
     * propriétaire, ou un administrateur autorisé à nommer les
     * responsables, pour un magasin de sa portée (une autorisation de
     * société ne suffit jamais seule pour un geste sur un magasin).
     */
    public function nommerUnResponsable(User $user, Magasin $magasin): bool
    {
        return $this->portee->peutNommerUnResponsable($user, $magasin);
    }

    /**
     * Créer un magasin dans sa société : le propriétaire, ou un
     * administrateur de tous les magasins autorisé à les gérer, tant que la
     * société n'est pas en lecture seule ; CreerEtablissementAction le
     * revérifie sur l'auteur relu.
     */
    public function create(User $user): bool
    {
        return $this->societes->creerUnMagasin($user, $user->societe);
    }

    /**
     * Modifier le magasin : son nom, son adresse, son code tant qu'il n'a
     * rien numéroté, son NIC avant la validation de la société (voir
     * ModifierEtablissementAction). La base de tous les gestes sur un
     * magasin : un magasin réel de sa portée, pour qui gère les magasins
     * d'une société qui n'est pas en lecture seule.
     */
    public function update(User $user, Magasin $magasin): bool
    {
        return ! $magasin->est_demonstration
            && $this->societes->gererLesMagasins($user, $magasin->societe)
            && $user->administreLeMagasin($magasin);
    }

    /**
     * Changer le SIRET d'un magasin qui déménage, une fois la société
     * validée (voir ChangerSiretAction).
     */
    public function changerLeSiret(User $user, Magasin $magasin): bool
    {
        return $this->update($user, $magasin);
    }

    /**
     * Fermer le magasin, temporairement ou pour cessation d'activité (voir
     * FermerMagasinAction).
     */
    public function fermerLeMagasin(User $user, Magasin $magasin): bool
    {
        return $this->update($user, $magasin);
    }

    /**
     * Rouvrir le magasin fermé (voir RouvrirMagasinAction).
     */
    public function rouvrirLeMagasin(User $user, Magasin $magasin): bool
    {
        return $this->update($user, $magasin);
    }

    /**
     * Céder le magasin, ouvert ou déjà fermé : le propriétaire seul.
     */
    public function cederLeMagasin(User $user, Magasin $magasin): bool
    {
        return $this->update($user, $magasin) && $user->estProprietaire();
    }

    /**
     * L'indiquer comme établissement principal de la société, ou retirer
     * cette indication : qui gère les magasins avec tous les magasins dans
     * sa portée, l'indication touchant aussi le magasin qui la portait
     * (voir ChoisirEtablissementPrincipalAction).
     */
    public function choisirCommePrincipal(User $user, Magasin $magasin): bool
    {
        return $this->update($user, $magasin) && $user->administreTousLesMagasins();
    }

    public function delete(User $user, Magasin $magasin): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }

    public function forceDelete(User $user, Magasin $magasin): bool
    {
        return false;
    }

    public function forceDeleteAny(User $user): bool
    {
        return false;
    }

    public function restore(User $user, Magasin $magasin): bool
    {
        return false;
    }

    public function restoreAny(User $user): bool
    {
        return false;
    }

    public function replicate(User $user, Magasin $magasin): bool
    {
        return false;
    }

    public function reorder(User $user): bool
    {
        return false;
    }
}
