<?php

namespace App\Domain\Cabinets\Exceptions;

use RuntimeException;

/**
 * Le lien du mot de passe d'un expert-comptable de la société ne sert
 * plus : expiré, déjà servi, remplacé, inconnu, ou d'un compte, d'une
 * déclaration ou d'une société qui n'est plus ouvert. Un seul message,
 * qui ne dit pas lequel (voir ChoisirLeMotDePasseDeLExpertComptableAction).
 */
class LienDeLExpertComptableInvalideException extends RuntimeException
{
    public const MESSAGE = 'Ce lien n\'est plus valable. Demandez au propriétaire de la société qui vous a créé ce compte de vous en envoyer un nouveau.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
