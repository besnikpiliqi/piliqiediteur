<?php

namespace App\Integrations\Courriel;

use LogicException;

/**
 * Un e-mail qui devait partir aussitôt, sans file d'attente (un lien
 * d'inscription, une invitation...), n'est pas parti : le serveur d'envoi
 * l'a refusé ou n'a pas répondu dans le délai MAIL_TIMEOUT (voir
 * EnvoiImmediat). Son message, en français, dit sa cause et quoi faire,
 * jamais le texte du serveur ; l'écran qui l'attrape l'affiche au lieu
 * d'une erreur 500 (voir GereLesExceptionsMetier).
 */
class CourrielNonParti extends LogicException
{
    public function __construct(string $message, public readonly CauseDEchecDEnvoi $cause)
    {
        parent::__construct($message);
    }
}
