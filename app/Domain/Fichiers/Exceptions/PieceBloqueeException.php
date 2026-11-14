<?php

namespace App\Domain\Fichiers\Exceptions;

use LogicException;

/**
 * Une pièce reçue que l'antivirus a reconnue comme une menace après son
 * dépôt (décision de l'éditeur du 07/11/2026, lot FI) : gardée comme pièce
 * justificative, elle ne se sert plus jamais, ni seule, ni dans un ZIP.
 */
final class PieceBloqueeException extends LogicException
{
    public const MESSAGE = 'Cette pièce est bloquée : l\'antivirus y a trouvé une menace. Elle reste conservée comme pièce justificative, mais ne se télécharge plus.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
