<?php

namespace App\Filament\App\Resources\ClotureCaisseResource\Widgets;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Services\VerificateurCaissesPersonnelles;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Vit délibérément sous Resources/ClotureCaisseResource/Widgets, PAS sous
 * Filament/App/Widgets : n'a de sens que sur la liste des clôtures,
 * attaché explicitement via ListClotureCaisses::getHeaderWidgets().
 *
 * Informe le gérant, avant qu'il ne clôture la journée, des vendeurs dont
 * la caisse personnelle est encore ouverte (voir
 * VerificateurCaissesPersonnelles) : la clôture ne les attend pas, elle les
 * ferme d'office (voir FermerLesCaissesPersonnellesAction).
 */
class CaissesPersonnellesOuvertesApercu extends BaseWidget
{
    public static function canView(): bool
    {
        return auth()->user()->can(PermissionRegistry::CLOTURE_CAISSE_GERER);
    }

    protected function getStats(): array
    {
        $vendeurs = app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(today());

        return [
            Stat::make('Caisses personnelles ouvertes', (string) $vendeurs->count())
                ->description($vendeurs->isEmpty()
                    ? 'Toutes les caisses personnelles sont fermées.'
                    : 'Fermées automatiquement à la clôture de la journée : '.$vendeurs->pluck('name')->implode(', '))
                ->color($vendeurs->isEmpty() ? 'success' : 'warning'),
        ];
    }
}
