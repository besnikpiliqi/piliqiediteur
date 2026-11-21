<?php

namespace App\Domain\Tenancy\Exceptions;

use App\Domain\Tenancy\Services\SiretsDesMagasins;
use LogicException;

/**
 * Le SIRET est déjà réservé par un autre magasin (voir SiretsDesMagasins) :
 * le message ne dit rien du magasin ni de la société qui l'a réservé.
 */
class SiretDejaReserveException extends LogicException
{
    public function __construct()
    {
        parent::__construct(SiretsDesMagasins::MESSAGE_SIRET_RESERVE);
    }
}
