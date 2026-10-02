<?php

namespace App\Domain\Facturation\Enums;

/**
 * Cycle de vie COMMERCIAL interne de la facture -- à ne jamais confondre
 * avec le statut de transmission réglementaire PDP (voir TransmissionPdp),
 * qui évolue indépendamment (une facture peut être "payee" ici tout en
 * ayant une transmission PDP encore "en_attente").
 */
enum StatutFacture: string
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
            self::EnRetard => [self::PartiellementPayee, self::Payee],
            self::PartiellementPayee, self::Payee, self::Annulee => [],
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
}
