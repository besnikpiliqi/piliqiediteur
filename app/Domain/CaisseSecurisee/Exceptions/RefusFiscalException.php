<?php

namespace App\Domain\CaisseSecurisee\Exceptions;

use RuntimeException;

/**
 * Le module fiscal refuse une opération qu'il ne peut pas enregistrer
 * conformément (art. 286 I-3° bis du CGI) : clé de signature absente ou
 * invalide (ModuleFiscalNonConfigureException), horloge du serveur qui
 * recule (HorlogeRegressiveException). Rien n'est écrit.
 *
 * Ce n'est pas un geste invalide de l'utilisateur, mais un incident du
 * serveur : l'écran affiche son message, rédigé pour l'utilisateur, et le
 * signale au journal de l'application (voir GereLesExceptionsMetier).
 */
abstract class RefusFiscalException extends RuntimeException {}
