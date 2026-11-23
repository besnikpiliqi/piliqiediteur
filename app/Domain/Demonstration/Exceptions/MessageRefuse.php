<?php

namespace App\Domain\Demonstration\Exceptions;

use RuntimeException;

/**
 * Un message signé refusé à sa réception, avec sa cause en français : le
 * site de démonstration le répond en 422, sans rien faire.
 */
final class MessageRefuse extends RuntimeException {}
