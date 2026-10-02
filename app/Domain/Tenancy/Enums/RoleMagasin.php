<?php

namespace App\Domain\Tenancy\Enums;

/**
 * Rôle "de base" rapide stocké sur le pivot magasin_user.
 *
 * Les permissions fines (invoice.issue, payment.create, ...) sont gérées
 * séparément par Spatie Permission en mode "teams" (team = magasin). Ce rôle
 * sert aux contrôles grossiers et non négociables : seul le Propriétaire
 * peut par exemple gérer le mandat PDP ou supprimer le magasin.
 */
enum RoleMagasin: string
{
    case Proprietaire = 'owner';
    case Administrateur = 'admin';
    case Comptable = 'accountant';
    case Vendeur = 'employee';
    case Observateur = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Proprietaire => 'Propriétaire',
            self::Administrateur => 'Administrateur',
            self::Comptable => 'Comptable',
            self::Vendeur => 'Vendeur',
            self::Observateur => 'Observateur',
        };
    }
}
