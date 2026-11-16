<?php

namespace App\Domain\Demonstration\Exceptions;

use RuntimeException;

/**
 * Un serveur de PILIQI dont la configuration ne convient pas au site qu'il
 * sert (voir SiteDePiliqi et SiteDePiliqiServiceProvider) : il refuse de
 * démarrer, ou l'opération s'arrête, avec la cause en français et ce qu'il
 * faut écrire. Aucun réglage n'est jamais remplacé en silence.
 */
final class SiteMalConfigureException extends RuntimeException {}
