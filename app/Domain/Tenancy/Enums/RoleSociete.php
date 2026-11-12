<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Le rôle d'un compte dans sa société (voir User::$role_societe) :
 * - le propriétaire, un seul par société (index unique partiel
 *   users_un_proprietaire_par_societe), qui supervise et contrôle : tous
 *   les droits dans la société et dans chacun de ses magasins, sans case
 *   ni affectation, sauf celui de vendre, qu'il n'a dans aucun magasin
 *   réel, seulement dans le magasin de démonstration tant qu'il est
 *   ouvert (voir ControleDesDroits, PermissionRegistry::duProprietaire()
 *   et PermissionRegistry::duProprietaireEnDemonstration()) ;
 * - un administrateur, créé ou nommé par le propriétaire seul, avec sa
 *   portée (tous les magasins, présents et futurs, ou une sélection : ses
 *   affectations sans rôle) et les autorisations que le propriétaire coche
 *   pour lui, dans la société (voir AutorisationsSociete) et dans les
 *   magasins (voir Affectation), celles de la vente seulement s'il les lui
 *   coche ;
 * - un collaborateur d'un cabinet d'expertise comptable, créé par le
 *   propriétaire du cabinet seul, qui n'existe que dans un cabinet (voir
 *   GardesDesDroits) : sans affectation ni case ni autorisation de
 *   société, il n'entre chez un client que par le lien de son cabinet,
 *   pour les clients que le propriétaire lui confie ;
 * - l'expert-comptable de la société, une personne du cabinet que le
 *   propriétaire déclare par son SIREN quand ce cabinet n'a pas d'espace
 *   sur PILIQI (voir DeclarerSonExpertComptableAction) : ni administrateur
 *   ni membre, sans affectation ni case ni autorisation de société, il
 *   ouvre les magasins réels de sa société que la déclaration lui ouvre,
 *   avec les autorisations qu'elle porte, comme un compte d'un cabinet
 *   lié ; il ne vend jamais, n'a jamais son identifiant pour mot de passe,
 *   ne change jamais de rôle, et son compte se retire de lui-même quand le
 *   cabinet de son SIREN est vérifié (voir RattacherUneDeclarationAction) ;
 * - un membre, affecté à un seul magasin, celui de son identifiant, avec
 *   un rôle de magasin (voir RoleMagasin) : responsable, nommé par le
 *   propriétaire ou un administrateur autorisé ; vendeur ou observateur,
 *   créé par le propriétaire ou un responsable du magasin.
 */
enum RoleSociete: string implements HasLabel
{
    case Proprietaire = 'proprietaire';
    case Administrateur = 'administrateur';
    case Membre = 'membre';
    case Collaborateur = 'collaborateur';
    case ExpertComptable = 'expert_comptable';

    public function getLabel(): string
    {
        return match ($this) {
            self::Proprietaire => 'Propriétaire',
            self::Administrateur => 'Administrateur',
            self::Membre => 'Membre',
            self::Collaborateur => 'Collaborateur du cabinet',
            self::ExpertComptable => 'Expert-comptable de la société',
        };
    }
}
