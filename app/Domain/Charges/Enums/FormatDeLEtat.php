<?php

namespace App\Domain\Charges\Enums;

/**
 * Les formats de l'état téléchargeable des charges (modèle E3 choisi par
 * l'éditeur le 15/11/2026) : le PDF pour garder, imprimer et envoyer,
 * l'Excel pour retravailler les chiffres (voir EtatDesCharges).
 */
enum FormatDeLEtat: string
{
    case Pdf = 'pdf';
    case Excel = 'xlsx';

    /**
     * Son choix dans le menu « Télécharger » de la page des charges.
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::Pdf => 'PDF : état des charges',
            self::Excel => 'Excel : état des charges',
        };
    }

    public function typeMime(): string
    {
        return match ($this) {
            self::Pdf => 'application/pdf',
            self::Excel => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        };
    }
}
