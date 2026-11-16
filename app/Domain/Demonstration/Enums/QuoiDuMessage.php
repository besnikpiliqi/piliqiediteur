<?php

namespace App\Domain\Demonstration\Enums;

/**
 * Ce que dit un message signé du site réel au site de démonstration (voir
 * MessagesSignes), liste fermée : créer une copie, renouveler son lien, la
 * remettre à zéro, la supprimer, ou dire son état. Le billet d'entrée du
 * bouton du propriétaire n'est pas un message : il a son audience à lui.
 */
enum QuoiDuMessage: string
{
    case Creer = 'creer';
    case RenouvelerLeLien = 'renouveler_le_lien';
    case Recommencer = 'recommencer';
    case Supprimer = 'supprimer';
    case Etat = 'etat';
}
