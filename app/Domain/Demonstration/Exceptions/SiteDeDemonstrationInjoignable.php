<?php

namespace App\Domain\Demonstration\Exceptions;

use RuntimeException;

/**
 * Le site de démonstration ne répond pas, ou répond autre chose qu'une
 * réponse du protocole : le message reste dans la boîte d'envoi et
 * repartira (voir EnvoyerLesMessagesALaDemonstration).
 */
final class SiteDeDemonstrationInjoignable extends RuntimeException {}
