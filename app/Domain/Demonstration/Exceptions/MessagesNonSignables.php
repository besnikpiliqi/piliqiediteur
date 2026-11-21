<?php

namespace App\Domain\Demonstration\Exceptions;

use LogicException;

/**
 * Le site réel ne peut pas signer un message ni un billet d'entrée : sa
 * clé des messages n'est pas réglée. Le message reste dans la boîte
 * d'envoi, et l'écran le dit en français.
 */
final class MessagesNonSignables extends LogicException {}
