<?php

namespace App\Integrations\Rne;

use RuntimeException;

/**
 * Le registre national des entreprises n'a pas répondu (injoignable,
 * identifiants refusés, réponse illisible) : rien n'est prouvé, ni dans un
 * sens ni dans l'autre. L'étape qui le demandait reste en attente,
 * réessayable, jamais validée par défaut.
 */
class RneIndisponibleException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Le registre national des entreprises ne répond pas pour le moment.');
    }
}
