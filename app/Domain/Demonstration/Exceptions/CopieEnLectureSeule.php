<?php

namespace App\Domain\Demonstration\Exceptions;

use App\Domain\Demonstration\Taches\PlaceDesCopies;
use RuntimeException;

/**
 * Une écriture refusée dans une copie du site de démonstration en lecture
 * seule, au-delà de sa place (lot DEMO-COPIE, C.16) : rien n'est écrit,
 * et l'écran le dit en français (voir ReponseDansUneCopieEnLectureSeule).
 */
final class CopieEnLectureSeule extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(PlaceDesCopies::MESSAGE);
    }
}
