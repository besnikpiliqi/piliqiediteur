<?php

namespace App\Domain\Facturation\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum StatutDevis: string implements HasColor, HasLabel
{
    case Brouillon = 'brouillon';
    case Envoye = 'envoye';
    case Accepte = 'accepte';
    case Refuse = 'refuse';
    case Expire = 'expire';

    /**
     * @return list<self>
     */
    public function transitionsAutorisees(): array
    {
        return match ($this) {
            self::Brouillon => [self::Envoye],
            self::Envoye => [self::Accepte, self::Refuse, self::Expire],
            self::Accepte, self::Refuse, self::Expire => [],
        };
    }

    public function peutTransitionnerVers(self $cible): bool
    {
        return in_array($cible, $this->transitionsAutorisees(), true);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Brouillon => 'Brouillon',
            self::Envoye => 'Envoyé',
            self::Accepte => 'Accepté',
            self::Refuse => 'Refusé',
            self::Expire => 'Expiré',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Brouillon => 'gray',
            self::Envoye => 'info',
            self::Accepte => 'success',
            self::Refuse, self::Expire => 'danger',
        };
    }
}
