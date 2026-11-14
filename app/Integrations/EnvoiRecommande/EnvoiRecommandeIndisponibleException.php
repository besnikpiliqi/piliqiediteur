<?php

namespace App\Integrations\EnvoiRecommande;

use RuntimeException;

/**
 * Le prestataire d'envoi recommandé n'a pas répondu : aucun pli n'est
 * parti, et rien de ce qui devait l'accompagner ne s'enregistre. L'envoi
 * se redemande plus tard.
 */
class EnvoiRecommandeIndisponibleException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Le service d\'envoi recommandé ne répond pas pour le moment : réessayez plus tard.');
    }
}
