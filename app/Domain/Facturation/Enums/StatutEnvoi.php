<?php

namespace App\Domain\Facturation\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Un envoi part en file d'attente (voir EnvoyerDocumentJob) : en attente
 * jusqu'à ce que le serveur d'envoi ait accepté l'e-mail, en échec quand
 * toutes ses tentatives ont échoué. Un envoi abouti ne repart jamais.
 */
enum StatutEnvoi: string implements HasColor, HasLabel
{
    case EnAttente = 'en_attente';
    case Envoye = 'envoye';
    case Echec = 'echec';

    public function getLabel(): string
    {
        return match ($this) {
            self::EnAttente => 'En attente',
            self::Envoye => 'Envoyé',
            self::Echec => 'Échec',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::EnAttente => 'gray',
            self::Envoye => 'success',
            self::Echec => 'danger',
        };
    }
}
