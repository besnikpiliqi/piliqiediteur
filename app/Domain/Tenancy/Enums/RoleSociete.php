<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Le rôle d'un compte dans sa société (voir User::$role_societe) : un seul
 * propriétaire par société (index unique partiel
 * users_un_proprietaire_par_societe), qui a tous les droits dans la
 * société et dans chacun de ses magasins, sans case ni rattachement (voir
 * ControleDesDroits) ; les autres comptes sont des membres, qui tiennent
 * leurs droits de leur rattachement à un magasin (voir MagasinUser).
 */
enum RoleSociete: string implements HasLabel
{
    case Proprietaire = 'proprietaire';
    case Membre = 'membre';

    public function getLabel(): string
    {
        return match ($this) {
            self::Proprietaire => 'Propriétaire',
            self::Membre => 'Membre',
        };
    }
}
