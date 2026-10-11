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
 * Tant qu'elle n'est ni validée ni refusée, son propriétaire prouve sa
 * qualité de représentant et ouvre le magasin de démonstration.
 *
 * Les actions du domaine posent elles-mêmes ces questions (voir
 * CreerEtablissementAction, ModifierEtablissementAction,
 * ModifierIdentiteSocieteAction, ModifierParametresFiscauxSocieteAction,
 * CorrigerSirenAvantValidationAction, les actions de la vérification et
 * OuvrirLaDemonstrationAction), en plus des écrans.
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

    /**
     * Prouver la qualité de représentant de la société (vérifier son
     * identité, demander l'accord d'un dirigeant, déposer des pièces,
     * demander et saisir le code d'un courrier, celui d'une réclamation
     * compris) : son propriétaire seul,
     * tant qu'elle n'est ni validée ni refusée, ni en lecture seule.
     */
    public function verifierLaSociete(User $user, Societe $societe): bool
    {
        return $this->view($user, $societe) && ! $societe->estEnLectureSeule() && ! $societe->estValidee();
    }

    /**
     * Ouvrir, ou rouvrir, le magasin de démonstration : son propriétaire,
     * tant qu'elle n'est ni validée ni refusée, ni en lecture seule.
     */
    public function ouvrirLaDemonstration(User $user, Societe $societe): bool
    {
        return $this->verifierLaSociete($user, $societe);
    }
}
