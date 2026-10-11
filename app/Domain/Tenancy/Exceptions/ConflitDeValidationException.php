<?php

namespace App\Domain\Tenancy\Exceptions;

use RuntimeException;

/**
 * Une autre société a validé le même SIREN, ou réservé l'un des SIRET,
 * pendant la validation d'une société (voir ValiderLaSocieteAction) : rien
 * n'est écrit, et la société devient une réclamation possible.
 */
class ConflitDeValidationException extends RuntimeException {}
