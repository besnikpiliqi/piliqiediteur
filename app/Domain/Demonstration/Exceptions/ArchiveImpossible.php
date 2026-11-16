<?php

namespace App\Domain\Demonstration\Exceptions;

use RuntimeException;

/**
 * L'archive de la caisse d'une copie n'a pas pu être faite ou relue (lot
 * DEMO-COPIE, C.18) : la copie n'est pas supprimée, et l'état publié le
 * dit.
 */
final class ArchiveImpossible extends RuntimeException {}
