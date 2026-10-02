<?php

namespace App\Domain\Stock\Enums;

enum TypeMouvementStock: string
{
    case Vente = 'vente';
    case ReceptionAchat = 'reception_achat';
    case RetourFournisseur = 'retour_fournisseur';
    case RetourClient = 'retour_client';
    case Ajustement = 'ajustement';

    /**
     * true = entre en stock (+), false = sort du stock (-).
     */
    public function estEntree(): bool
    {
        return match ($this) {
            self::ReceptionAchat, self::RetourClient => true,
            self::Vente, self::RetourFournisseur => false,
            self::Ajustement => throw new \LogicException(
                "Le sens d'un ajustement doit être explicite, voir StockLedgerService::ajuster()."
            ),
        };
    }
}
