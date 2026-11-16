<?php

namespace App\Domain\Demonstration\Exceptions;

use RuntimeException;

/**
 * Une construction de base modèle qui n'a pas abouti (lot DEMO-COPIE,
 * C.2) : l'ancien modèle reste en service, la base de travail est
 * supprimée et la cause est écrite au registre.
 */
final class ConstructionEnEchec extends RuntimeException {}
