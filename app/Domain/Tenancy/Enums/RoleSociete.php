<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Le rôle d'un compte dans sa société (voir User::$role_societe) :
 * - le propriétaire, un seul par société (index unique partiel
 *   users_un_proprietaire_par_societe), qui supervise et contrôle : tous
 *   les droits dans la société et dans chacun de ses magasins, sans case
 *   ni affectation, sauf celui de vendre, qu'il n'a jamais (voir
 *   ControleDesDroits et PermissionRegistry::duProprietaire()) ;
 * - un administrateur, créé ou nommé par le propriétaire seul, avec sa
 *   portée (tous les magasins, présents et futurs, ou une sélection : ses
 *   affectations sans rôle) et les autorisations que le propriétaire coche
 *   pour lui, dans la société (voir AutorisationsSociete) et dans les
 *   magasins (voir Affectation), celles de la vente seulement s'il les lui
 *   coche ;
 * - un expert-comptable, créé par le propriétaire seul (un compte par
 *   collaborateur du cabinet), sans affectation ni case : il lit chaque
 *   magasin réel de la société, présent ou futur, et n'écrit rien (voir
 *   PermissionRegistry::consultations()) ;
 * - un membre, affecté à un ou plusieurs magasins avec un rôle de magasin
 *   dans chacun (voir RoleMagasin) : responsable, nommé par le propriétaire
 *   ou un administrateur autorisé ; vendeur ou observateur, créé par le
 *   propriétaire ou un responsable du magasin.
 */
enum RoleSociete: string implements HasLabel
{
    case Proprietaire = 'proprietaire';
    case Administrateur = 'administrateur';
    case ExpertComptable = 'expert_comptable';
    case Membre = 'membre';

    public function getLabel(): string
    {
        return match ($this) {
            self::Proprietaire => 'Propriétaire',
            self::Administrateur => 'Administrateur',
            self::ExpertComptable => 'Expert-comptable',
            self::Membre => 'Membre',
        };
    }
}
