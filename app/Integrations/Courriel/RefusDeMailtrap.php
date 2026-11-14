<?php

namespace App\Integrations\Courriel;

use Symfony\Component\Mailer\Exception\TransportException;

/**
 * Un e-mail que la boîte de test de Mailtrap n'a pas pris (voir
 * TransportMailtrap) : son message dit la cause et le remède en français,
 * sans le jeton ni le numéro de la boîte ; son code est le statut HTTP de
 * la réponse (0 quand l'API n'a pas répondu) ; sa cause se lit sans
 * deviner (voir CauseDEchecDEnvoi::de()).
 */
class RefusDeMailtrap extends TransportException
{
    public function __construct(string $message, public readonly CauseDEchecDEnvoi $cause, int $statut = 0)
    {
        parent::__construct($message, $statut);
    }
}
