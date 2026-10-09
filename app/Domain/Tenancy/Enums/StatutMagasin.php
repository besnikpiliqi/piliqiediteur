<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Un magasin est actif dès sa création (voir CreerMagasinAction). Suspendu
 * ou fermé, il passe en lecture seule (voir estEnLectureSeule()) : la
 * consultation et les téléchargements restent possibles, plus aucune vente
 * ni aucun e-mail aux clients, tandis que ses clôtures, les statuts de ses
 * factures et ses déclarations continuent jusqu'au bout.
 */
enum StatutMagasin: string implements HasLabel
{
    case Active = 'active';
    case Suspendu = 'suspended';
    case Ferme = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Actif',
            self::Suspendu => 'Suspendu',
            self::Ferme => 'Fermé',
        };
    }

    /**
     * Ce que le magasin peut encore faire, voir ControleDesDroits et
     * Magasin::verifierQuIlEstOuvert().
     */
    public function estEnLectureSeule(): bool
    {
        return $this !== self::Active;
    }
}
