<?php

namespace App\Policies;

use App\Domain\Authorization\AutorisationsSociete;
use App\Domain\Tenancy\Models\Societe;
use App\Models\User;

/**
 * Les gestes sur la société elle-même, dans son espace d'administration
 * (voir SocietePanelProvider), jamais pour le compte d'une autre société :
 * - la consulter (tableau de bord, vérification) : son propriétaire et ses
 *   administrateurs, avec un compte qui peut agir (voir view()) ;
 * - ses paramètres (identité légale, fiscalité) : le propriétaire et un
 *   administrateur de tous les magasins autorisé à les gérer, qui les
 *   consultent même en lecture seule et ne les modifient qu'en dehors ;
 * - ses magasins : qui est autorisé à les gérer, dans sa portée ; en créer,
 *   seulement avec tous les magasins (voir creerUnMagasin()) ;
 * - réservés au propriétaire (voir les gestes ci-dessous) : corriger le
 *   SIREN tant que la société n'est pas validée, prouver sa qualité de
 *   représentant, transmettre la propriété ; ouvrir le magasin de
 *   démonstration, aussi pour un administrateur de tous les magasins
 *   autorisé à les gérer. Les gestes sur les comptes, administrateurs
 *   compris, sont dans MembrePolicy.
 *
 * Une société en lecture seule (suspendue, résiliée, révoquée ou refusée,
 * voir Societe::estEnLectureSeule()) reste consultable, mais aucun de ses
 * réglages ne se modifie et aucun magasin ne s'y crée ni ne s'y modifie ;
 * ControleDesDroits refuse en amont tout autre geste qu'une consultation ou
 * un geste sur l'équipe.
 *
 * Les actions du domaine posent elles-mêmes ces questions (voir
 * CreerEtablissementAction, ModifierEtablissementAction,
 * ModifierIdentiteSocieteAction, ModifierParametresFiscauxSocieteAction,
 * CorrigerSirenAvantValidationAction, les actions de la vérification,
 * OuvrirLaDemonstrationAction et TransfererProprieteAction), en plus des
 * écrans.
 */
class SocietePolicy
{
    public function view(User $user, Societe $societe): bool
    {
        return $user->peutAdministrer() && $user->peutAgir() && (int) $user->societe_id === $societe->getKey();
    }

    /**
     * Ouvrir l'identité légale et les paramètres fiscaux, même en lecture
     * seule : le propriétaire, et un administrateur de tous les magasins
     * autorisé à gérer les paramètres de la société.
     */
    public function voirLesParametres(User $user, Societe $societe): bool
    {
        return $this->view($user, $societe)
            && $user->administreTousLesMagasins()
            && in_array(AutorisationsSociete::PARAMETRES_GERER, $user->autorisationsDeSociete(), true);
    }

    public function modifierLesParametres(User $user, Societe $societe): bool
    {
        return $this->voirLesParametres($user, $societe) && ! $societe->estEnLectureSeule();
    }

    /**
     * Modifier les magasins de sa portée (voir aussi
     * User::administreLeMagasin(), que l'action vérifie).
     */
    public function gererLesMagasins(User $user, Societe $societe): bool
    {
        return $this->view($user, $societe)
            && ! $societe->estEnLectureSeule()
            && $user->aLAutorisationDeSociete(AutorisationsSociete::MAGASINS_GERER);
    }

    /**
     * Créer un magasin : qui gère les magasins avec tous les magasins dans
     * sa portée.
     */
    public function creerUnMagasin(User $user, Societe $societe): bool
    {
        return $this->gererLesMagasins($user, $societe) && $user->administreTousLesMagasins();
    }

    public function corrigerLeSiren(User $user, Societe $societe): bool
    {
        return $this->modifierLesParametres($user, $societe) && $user->estProprietaire() && ! $societe->estValidee();
    }

    /**
     * Prouver la qualité de représentant de la société (vérifier son
     * identité, demander l'accord d'un dirigeant, déposer des pièces,
     * demander et saisir le code d'un courrier, celui d'une réclamation
     * compris) : son propriétaire seul, tant qu'elle n'est ni validée ni
     * refusée, ni en lecture seule.
     */
    public function verifierLaSociete(User $user, Societe $societe): bool
    {
        return $this->view($user, $societe) && $user->estProprietaire() && ! $societe->estEnLectureSeule() && ! $societe->estValidee();
    }

    /**
     * Ouvrir, ou rouvrir, le magasin de démonstration : son propriétaire, ou
     * un administrateur de tous les magasins autorisé à les gérer, tant que
     * la société n'est ni validée ni refusée, ni en lecture seule.
     */
    public function ouvrirLaDemonstration(User $user, Societe $societe): bool
    {
        return $this->view($user, $societe)
            && ! $societe->estEnLectureSeule()
            && ! $societe->estValidee()
            && ($user->estProprietaire() || ($user->administreTousLesMagasins() && $user->aLAutorisationDeSociete(AutorisationsSociete::MAGASINS_GERER)));
    }
}
