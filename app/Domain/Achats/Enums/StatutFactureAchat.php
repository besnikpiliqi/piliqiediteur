<?php

namespace App\Domain\Achats\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Cycle de vie volontairement minimal : contrairement à une facture de
 * vente, ce document n'est jamais envoyé ni payé dans ce système (le
 * paiement au fournisseur se fait hors de l'application) -- il sert
 * uniquement à enregistrer une réception de marchandise et à tracer le
 * stock qui en découle. Un brouillon n'a aucune existence légale ni
 * impact sur le stock ; la validation attribue le numéro interne et
 * incrémente le stock (voir ValiderFactureAchatAction).
 *
 * Pas de statut "annulée" : une facture saisie manuellement, validée et
 * sans avoir fournisseur émis, se corrige directement (voir
 * ModifierFactureAchatAction) ; sinon (facture récupérée par API, ou déjà
 * retournée en partie) par un avoir fournisseur (voir
 * FactureAchat::estModifiable()) -- jamais par une annulation rétroactive
 * de la réception.
 */
enum StatutFactureAchat: string implements HasColor, HasLabel
{
    case Brouillon = 'brouillon';
    case Validee = 'validee';

    /**
     * @return list<self>
     */
    public function transitionsAutorisees(): array
    {
        return match ($this) {
            self::Brouillon => [self::Validee],
            self::Validee => [],
        };
    }

    public function peutTransitionnerVers(self $cible): bool
    {
        return in_array($cible, $this->transitionsAutorisees(), true);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Validee => 'Validée',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Brouillon => 'gray',
            self::Validee => 'success',
        };
    }
}
