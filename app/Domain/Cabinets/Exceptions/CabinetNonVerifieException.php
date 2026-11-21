<?php

namespace App\Domain\Cabinets\Exceptions;

use LogicException;

/**
 * Un cabinet d'expertise comptable qui n'est pas encore, ou plus, vérifié
 * (voir Societe::estUnCabinetVerifie()) n'invite ni ne lie aucun client.
 */
class CabinetNonVerifieException extends LogicException
{
    public const MESSAGE = 'Votre cabinet doit être validé, contrôlé 69.20Z et vérifié à l\'Ordre avant d\'inviter ou de lier un client.';

    public function __construct(string $message = self::MESSAGE)
    {
        parent::__construct($message);
    }
}
