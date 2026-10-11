<?php

namespace App\Policies;

use App\Domain\Tenancy\Models\Societe;
use App\Models\User;

/**
 * Les gestes sur la société elle-même, dans son espace d'administration
 * (voir SocietePanelProvider) : réservés à son propriétaire, avec un
 * compte actif (voir User::peutAdministrer()), jamais à celui d'une autre
 * société.
 *
 * Une société en lecture seule (suspendue, résiliée, révoquée ou refusée,
 * voir Societe::estEnLectureSeule()) reste consultable, mais aucun de ses
 * réglages ne se modifie et aucun magasin ne s'y crée ni ne s'y modifie.
 * Le SIREN ne se corrige que tant que la société n'est pas validée : la
 * validation le verrouille (voir CorrigerSirenAvantValidationAction).
 *
 * Les actions du domaine posent elles-mêmes ces questions (voir
 * CreerEtablissementAction, ModifierEtablissementAction,
 * ModifierIdentiteSocieteAction, ModifierParametresFiscauxSocieteAction,
 * CorrigerSirenAvantValidationAction), en plus des écrans.
 */
class SocietePolicy
{
    public function view(User $user, Societe $societe): bool
    {
        return $user->peutAdministrer() && (int) $user->societe_id === $societe->getKey();
    }

    public function modifierLesParametres(User $user, Societe $societe): bool
    {
        return $this->view($user, $societe) && ! $societe->estEnLectureSeule();
    }

    /**
     * Créer et modifier ses magasins.
     */
    public function gererLesMagasins(User $user, Societe $societe): bool
    {
        return $this->view($user, $societe) && ! $societe->estEnLectureSeule();
    }

    public function corrigerLeSiren(User $user, Societe $societe): bool
    {
        return $this->modifierLesParametres($user, $societe) && ! $societe->estValidee();
    }
}
