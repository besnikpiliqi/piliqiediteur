<?php

namespace App\Domain\Demonstration\Exceptions;

use RuntimeException;

/**
 * Une copie qui n'a pas pu être créée cette fois (lot DEMO-COPIE, C.3) :
 * aucun modèle en service, modèle occupé, création refusée par le serveur.
 * Aucune base à moitié faite ne reste ; le travail réessaie.
 */
final class CopieImpossible extends RuntimeException {}
