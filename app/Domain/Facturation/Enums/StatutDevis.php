<?php

namespace App\Domain\Facturation\Enums;

enum StatutDevis: string
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
}
