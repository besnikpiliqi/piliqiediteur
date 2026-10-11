<?php

namespace App\Domain\Tenancy\Exceptions;

use LogicException;

/**
 * Le magasin ne peut pas faire cette opération commerciale (voir
 * Magasin::peutVendre()) : en lecture seule, société en cours de
 * vérification, ou établissement pas encore confirmé par le répertoire
 * Sirene. Le message, rédigé pour l'utilisateur, dit lequel. Rien n'est
 * numéroté, signé ni envoyé.
 */
class VenteNonAutoriseeException extends LogicException {}
