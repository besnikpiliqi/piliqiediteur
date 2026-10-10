<?php

namespace App\Filament\App\Resources\ClotureCaisseResource\Widgets;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Actions\CloturerLesJourneesAction;
use App\Domain\Facturation\Services\VerificateurCaissesPersonnelles;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Vit délibérément sous Resources/ClotureCaisseResource/Widgets, PAS sous
 * Filament/App/Widgets : n'a de sens que sur la liste des clôtures,
 * attaché explicitement via ListClotureCaisses::getHeaderWidgets().
 *
 * Alerte le gérant AVANT qu'il ne clôture manuellement si un vendeur a
 * encore des ventes ou des encaissements de l'une des journées à clôturer
 * qu'il n'a pas fermés de son côté (voir VerificateurCaissesPersonnelles)
 * -- le bouton « Clôturer la journée » reste de toute façon désactivé tant
 * que la liste n'est pas vide (voir ListClotureCaisses::getHeaderActions()).
 */
class CaissesPersonnellesOuvertesApercu extends BaseWidget
{
    public static function canView(): bool
    {
        return auth()->user()->can(PermissionRegistry::CLOTURE_CAISSE_GERER);
    }

    protected function getStats(): array
    {
        $vendeurs = app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverteCesJours(
            app(CloturerLesJourneesAction::class)->journeesEnAttente(today()),
        );

        return [
            Stat::make('Caisses personnelles non fermées', (string) $vendeurs->count())
                ->description($vendeurs->isEmpty()
                    ? 'Tous les vendeurs ont fermé leur caisse pour les journées à clôturer.'
                    : 'En attente : '.$vendeurs->pluck('name')->implode(', '))
                ->color($vendeurs->isEmpty() ? 'success' : 'danger'),
        ];
    }
}
