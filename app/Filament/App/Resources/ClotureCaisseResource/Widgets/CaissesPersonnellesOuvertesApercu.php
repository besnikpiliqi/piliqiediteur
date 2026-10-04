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
 * Alerte le propriétaire AVANT qu'il ne clôture manuellement si un
 * vendeur a encore des ventes de la journée qu'il n'a pas fermées de son
 * côté (voir VerificateurCaissesPersonnelles) -- l'action "Clôturer une
 * journée" reste de toute façon désactivée tant que la liste n'est pas
 * vide (voir ListClotureCaisses::getHeaderActions()).
 */
class CaissesPersonnellesOuvertesApercu extends BaseWidget
{
    public static function canView(): bool
    {
        return auth()->user()->can(PermissionRegistry::CLOTURE_CAISSE_GERER);
    }

    protected function getStats(): array
    {
        $veille = now()->subDay();

        $vendeurs = app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte($veille);

        return [
            Stat::make('Caisses personnelles non fermées', (string) $vendeurs->count())
                ->description($vendeurs->isEmpty()
                    ? 'Tous les vendeurs ont fermé leur caisse pour cette journée.'
                    : 'En attente : '.$vendeurs->pluck('name')->implode(', '))
                ->color($vendeurs->isEmpty() ? 'success' : 'danger'),
        ];
    }
}
