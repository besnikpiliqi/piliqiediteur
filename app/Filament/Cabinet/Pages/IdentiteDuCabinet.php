<?php

namespace App\Filament\Cabinet\Pages;

use App\Domain\Tenancy\Models\Societe;
use App\Filament\Societe\Pages\IdentiteSociete;
use Filament\Facades\Filament;
use Illuminate\Contracts\Support\Htmlable;

/**
 * L'identité légale d'un cabinet d'expertise comptable, dans l'espace du
 * cabinet : le même formulaire et la même action que l'identité d'une
 * société (voir IdentiteSociete et ModifierIdentiteSocieteAction), son
 * SIREN et son type d'activité en lecture seule, sans encadré de ce qui
 * manque pour facturer ni assurance professionnelle, qu'un cabinet n'a
 * pas. Tous les comptes du cabinet la consultent, son propriétaire seul la
 * modifie, hors lecture seule (voir SocietePolicy::voirLesParametres() et
 * modifierLesParametres()).
 */
class IdentiteDuCabinet extends IdentiteSociete
{
    public static function canAccess(): bool
    {
        $societe = Filament::getTenant();

        return $societe instanceof Societe && $societe->estUnCabinet() && (auth()->user()?->can('voirLesParametres', $societe) ?? false);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Identité du cabinet';
    }
}
