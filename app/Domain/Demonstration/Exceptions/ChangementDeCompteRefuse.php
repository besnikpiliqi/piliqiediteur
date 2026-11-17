<?php

namespace App\Domain\Demonstration\Exceptions;

use RuntimeException;

/**
 * Un changement de compte du bandeau « Vous êtes : » refusé, avec sa
 * raison en français (voir ChangerDeCompteAction).
 */
final class ChangementDeCompteRefuse extends RuntimeException {}
