<?php

namespace App\Domain\CaisseSecurisee\Exceptions;

use RuntimeException;

/**
 * Le module fiscal refuse une opération qu'il ne peut pas faire
 * conformément (art. 286 I-3° bis du CGI) : enregistrer avec une clé de
 * signature absente ou invalide (ModuleFiscalNonConfigureException) ou
 * une horloge du serveur qui recule (HorlogeRegressiveException),
 * reproduire un document numéroté qui n'a aucun enregistrement signé
 * (DocumentSansEnregistrementSigneException). Rien n'est écrit ni
 * produit.
 *
 * Ce n'est pas un geste invalide de l'utilisateur, mais un incident du
 * serveur : l'écran affiche son message, rédigé pour l'utilisateur, et le
 * signale au journal de l'application (voir GereLesExceptionsMetier).
 */
abstract class RefusFiscalException extends RuntimeException {}
