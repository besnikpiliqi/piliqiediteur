<?php

namespace App\Integrations\Antivirus;

use RuntimeException;
use Throwable;

/**
 * L'antivirus ne répond pas, ou n'a pas su analyser un contenu : le
 * fichier n'est jamais accepté ni servi sans analyse (décision de
 * l'éditeur du 07/11/2026, lot FI) ; le geste se refait dans quelques
 * minutes. La cause reste au journal de l'application.
 */
class AntivirusInjoignableException extends RuntimeException
{
    public function __construct(string $cause, ?Throwable $precedente = null)
    {
        parent::__construct("L'antivirus ne répond pas : {$cause}", 0, $precedente);
    }
}
