<?php

namespace App\Integrations\Ordre;

use RuntimeException;

/**
 * L'annuaire de l'Ordre des experts-comptables ne répond pas : la
 * vérification se réessaie plus tard, rien n'est écrit.
 */
class AnnuaireDeLOrdreIndisponibleException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('L\'annuaire de l\'Ordre des experts-comptables ne répond pas : réessayez dans quelques minutes.');
    }
}
