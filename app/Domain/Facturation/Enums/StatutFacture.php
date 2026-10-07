<?php

namespace App\Domain\Facturation\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Cycle de vie COMMERCIAL interne de la facture -- à ne jamais confondre
 * avec le statut de transmission réglementaire PDP (voir TransmissionPdp),
 * qui évolue indépendamment (une facture peut être "payee" ici tout en
 * ayant une transmission PDP encore "en_attente").
 */
enum StatutFacture: string implements HasColor, HasLabel
{
    case Brouillon = 'brouillon';
    case Emise = 'emise';
    case Envoyee = 'envoyee';
    case PartiellementPayee = 'partiellement_payee';
    case Payee = 'payee';
    case EnRetard = 'en_retard';
    case Annulee = 'annulee';

    /**
     * @return list<self>
     */
    public function transitionsAutorisees(): array
    {
        return match ($this) {
            self::Brouillon => [self::Emise, self::Annulee],
            self::Emise => [self::Envoyee, self::PartiellementPayee, self::Payee, self::EnRetard, self::Annulee],
            self::Envoyee => [self::PartiellementPayee, self::Payee, self::EnRetard, self::Annulee],
            // Une facture partiellement payée devient "payee" une fois le
            // solde réglé (un deuxième paiement par exemple), et reste
            // détectable comme en retard si l'échéance passe entre-temps.
            self::PartiellementPayee => [self::Payee, self::EnRetard],
            self::EnRetard => [self::PartiellementPayee, self::Payee],
            self::Payee, self::Annulee => [],
        };
    }

    public function peutTransitionnerVers(self $cible): bool
    {
        return in_array($cible, $this->transitionsAutorisees(), true);
    }

    public function estDefinitive(): bool
    {
        return in_array($this, [self::Payee, self::Annulee], true);
    }

    /**
     * Une facture émise dont il reste quelque chose à régler : elle peut
     * recevoir un paiement, ou l'imputation d'un avoir qui la corrige.
     */
    public function attendUnReglement(): bool
    {
        return in_array($this, [self::Emise, self::Envoyee, self::PartiellementPayee, self::EnRetard], true);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Emise => 'Émise',
            self::Envoyee => 'Envoyée',
            self::PartiellementPayee => 'Partiellement payée',
            self::Payee => 'Payée',
            self::EnRetard => 'En retard',
            self::Annulee => 'Annulée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Brouillon => 'gray',
            self::Emise, self::Envoyee => 'info',
            self::PartiellementPayee => 'warning',
            self::Payee => 'success',
            self::EnRetard, self::Annulee => 'danger',
        };
    }
}
