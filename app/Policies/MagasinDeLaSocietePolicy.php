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
 * société (voir create()) ; les autres gestes sur un magasin (modifier,
 * fermer...) passent par SocietePolicy et les actions du domaine, qui
 * vérifient la portée ; nommer un responsable dans un magasin, par
 * nommerUnResponsable().
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

    public function update(User $user, Magasin $magasin): bool
    {
        return false;
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
