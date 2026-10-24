<?php

namespace App\Domain\Facturation\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Cycle de vie COMMERCIAL interne de la facture -- à ne jamais confondre
 * avec le statut de transmission réglementaire PDP (voir TransmissionPdp),
 * qui évolue indépendamment (une facture peut être "payee" ici tout en
 * ayant une transmission PDP encore "en_attente").
 *
 * Une facture émise ne redevient jamais un brouillon et ne disparaît
 * jamais (art. 286 du CGI) : elle se corrige par un avoir, total ou
 * partiel, qui est un document à part (voir EnregistrerAvoirAction).
 * Seul un brouillon, qui n'a pas encore de numéro, se supprime (voir
 * FacturePolicy::delete()).
 */
enum StatutFacture: string implements HasColor, HasLabel
{
    case Brouillon = 'brouillon';
    case Emise = 'emise';
    case Envoyee = 'envoyee';
    case PartiellementPayee = 'partiellement_payee';
    case Payee = 'payee';
    case EnRetard = 'en_retard';

    /**
     * Le statut d'une facture émise suit ses règlements (voir
     * Facture::constaterUnReglement()) : la contre-passation d'un paiement
     * saisi par erreur (voir ContrePasserPaiementAction) rouvre une facture
     * payée ou partiellement payée, qui redevient partiellement payée, en
     * retard, envoyée ou émise.
     *
     * @return list<self>
     */
    public function transitionsAutorisees(): array
    {
        return match ($this) {
            self::Brouillon => [self::Emise],
            self::Emise => [self::Envoyee, self::PartiellementPayee, self::Payee, self::EnRetard],
            self::Envoyee => [self::PartiellementPayee, self::Payee, self::EnRetard],
            // Une facture partiellement payée devient "payee" une fois le
            // solde réglé (un deuxième paiement par exemple), et reste
            // détectable comme en retard si l'échéance passe entre-temps.
            self::PartiellementPayee => [self::Payee, self::EnRetard, self::Emise, self::Envoyee],
            // Une facture en retard le reste tant qu'un montant reste dû
            // après l'échéance (G1), même après un paiement partiel ou la
            // contre-passation d'un paiement.
            self::EnRetard => [self::Payee],
            self::Payee => [self::PartiellementPayee, self::EnRetard, self::Emise, self::Envoyee],
        };
    }

    public function peutTransitionnerVers(self $cible): bool
    {
        return in_array($cible, $this->transitionsAutorisees(), true);
    }

    /**
     * Une facture émise dont il reste quelque chose à régler : elle peut
     * recevoir un paiement, ou l'imputation d'un avoir qui la corrige.
     */
    public function attendUnReglement(): bool
    {
        return in_array($this, self::quiAttendentUnReglement(), true);
    }

    /**
     * @return list<self>
     */
    public static function quiAttendentUnReglement(): array
    {
        return [self::Emise, self::Envoyee, self::PartiellementPayee, self::EnRetard];
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
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Brouillon => 'gray',
            self::Emise, self::Envoyee => 'info',
            self::PartiellementPayee => 'warning',
            self::Payee => 'success',
            self::EnRetard => 'danger',
        };
    }
}
