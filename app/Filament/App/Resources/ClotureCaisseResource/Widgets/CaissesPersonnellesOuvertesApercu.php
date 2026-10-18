<?php

namespace App\Filament\App\Resources\ClotureCaisseResource\Widgets;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Services\VerificateurCaissesPersonnelles;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Vit délibérément sous Resources/ClotureCaisseResource/Widgets, PAS sous
 * Filament/App/Widgets : n'a de sens que sur la liste des clôtures,
 * attaché explicitement via ListClotureCaisses::getHeaderWidgets(). La
 * page des relevés de caisse (« Les caisses ») en montre la même chose à
 * qui y voit les relevés de toute l'équipe (voir
 * CaissesOuvertesDeLEquipeApercu).
 *
 * Informe qui peut clôturer la journée, avant qu'il ne le fasse, des
 * vendeurs dont la caisse personnelle est encore ouverte (voir
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
