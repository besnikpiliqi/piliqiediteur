<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Un magasin est actif dès sa création, et la société le ferme et le
 * rouvre elle-même. Fermé, il passe en lecture seule, comme un magasin
 * suspendu par l'éditeur ou dont la société n'est plus active (voir
 * Magasin::estEnLectureSeule()) : la consultation et les téléchargements
 * restent possibles, plus aucune vente ni aucun e-mail aux clients, tandis
 * que ses clôtures, les statuts de ses factures et ses déclarations
 * continuent jusqu'au bout.
 */
enum StatutMagasin: string implements HasLabel
{
    case Actif = 'actif';
    case Ferme = 'ferme';

    public function getLabel(): string
    {
        return match ($this) {
            self::Actif => 'Actif',
            self::Ferme => 'Fermé',
        };
    }
}
