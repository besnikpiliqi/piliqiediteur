<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Rôle "de base" rapide stocké sur le pivot magasin_user.
 *
 * Les permissions fines (invoice.issue, payment.create, ...) sont gérées
 * séparément par Spatie Permission en mode "teams" (team = magasin). Ce rôle
 * sert aux contrôles grossiers et non négociables : seul le Propriétaire
 * peut par exemple gérer le mandat PDP ou supprimer le magasin.
 */
enum RoleMagasin: string implements HasLabel
{
    case Proprietaire = 'owner';
    case Administrateur = 'admin';
    case Comptable = 'accountant';
    case Vendeur = 'employee';
    case Observateur = 'viewer';

    public function getLabel(): string
    {
        return match ($this) {
            self::Proprietaire => 'Propriétaire',
            self::Administrateur => 'Administrateur',
            self::Comptable => 'Comptable',
            self::Vendeur => 'Vendeur',
            self::Observateur => 'Observateur',
        };
    }

    /**
     * Rôles qu'un propriétaire peut attribuer à un membre de son équipe.
     * "owner" est exclu : il n'existe qu'un seul chemin pour le devenir
     * (la création du magasin elle-même), jamais via la gestion d'équipe.
     *
     * @return list<self>
     */
    public static function assignables(): array
    {
        return [self::Administrateur, self::Comptable, self::Vendeur, self::Observateur];
    }
}
