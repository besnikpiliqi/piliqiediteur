<?php

namespace App\Integrations\Sirene;

use RuntimeException;

/**
 * Le répertoire Sirene n'a pas répondu (injoignable, saturé, réponse
 * illisible) : rien ne prouve alors qu'une entreprise ou un établissement
 * existe, ni qu'il n'existe pas. Celui qui le demande décide : une
 * inscription est acceptée et le contrôle repris plus tard, un nouveau
 * magasin est créé « établissement à vérifier » (voir
 * app:reverifier-sirene).
 */
class SireneIndisponibleException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Le répertoire Sirene de l\'INSEE ne répond pas pour le moment.');
    }
}
