<?php

namespace App\Domain\Cabinets\Exceptions;

use LogicException;

/**
 * Le lien d'une invitation d'un cabinet ne vaut plus : absente, close ou
 * périmée ; un seul message, qui ne dit pas pourquoi.
 */
class InvitationInvalideException extends LogicException
{
    public const MESSAGE = 'Ce lien d\'invitation n\'est plus valable. Demandez une nouvelle invitation au cabinet.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
