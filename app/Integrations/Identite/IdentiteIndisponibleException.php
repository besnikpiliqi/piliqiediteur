<?php

namespace App\Integrations\Identite;

use RuntimeException;

/**
 * Le prestataire de vérification d'identité n'a pas répondu, ou ne
 * reconnaît pas la session (référence inconnue, state différent) : rien
 * n'est prouvé, et l'étape reste en attente, réessayable.
 */
class IdentiteIndisponibleException extends RuntimeException
{
    public function __construct(string $message = 'Le service de vérification d\'identité ne répond pas pour le moment : réessayez plus tard.')
    {
        parent::__construct($message);
    }
}
