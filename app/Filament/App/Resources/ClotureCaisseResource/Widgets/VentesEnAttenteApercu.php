<?php

namespace App\Filament\App\Resources\ClotureCaisseResource\Widgets;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Actions\CloturerLesJourneesAction;
use App\Filament\App\Resources\ClotureCaisseResource;
use App\Filament\Concerns\PrendLeCalendrierDuMagasin;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Vit délibérément sous Resources/ClotureCaisseResource/Widgets, PAS sous
 * Filament/App/Widgets (scanné par discoverWidgets() et donc rendu sur le
 * tableau de bord général) : ce widget n'a de sens que sur la liste des
 * clôtures, attaché explicitement via ListClotureCaisses::getHeaderWidgets().
 *
 * Aperçu de ce que regroupera la prochaine clôture : les journées encore
 * ouvertes jusqu'à aujourd'hui (une journée oubliée comprise, voir
 * CloturerLesJourneesAction), leurs ventes au comptoir et les avoirs du
 * même jour, avec les mêmes critères que CloturerCaisseAction, pour que
 * le gérant voie directement sur la page ce qui va être clôturé, pas
 * seulement dans la fenêtre de confirmation au moment de cliquer.
 */
class VentesEnAttenteApercu extends BaseWidget
{
    use PrendLeCalendrierDuMagasin;

    public static function canView(): bool
    {
        return auth()->user()->can(PermissionRegistry::CLOTURE_CAISSE_GERER);
    }

    protected function getStats(): array
    {
        $journees = app(CloturerLesJourneesAction::class)->journeesEnAttente(self::calendrierDuMagasin()->aujourdhui());

        if ($journees->isEmpty()) {
            return [
                Stat::make('Ventes en attente de clôture', '0')
                    ->description('Aucune journée à clôturer.')
                    ->color('gray'),
            ];
        }

        $enAttente = ClotureCaisseResource::enAttenteDeCloture($journees);

        return [
            Stat::make('Ventes en attente de clôture', (string) $enAttente['ventes'])
                ->description(sprintf(
                    '%s %s · %s%s € au total',
                    $journees->count() > 1 ? 'Journées du' : 'Journée du',
                    ClotureCaisseResource::libelleDesJournees($journees),
                    $enAttente['avoirs'] === 0 ? '' : "{$enAttente['avoirs']} avoir(s) déduit(s) · ",
                    number_format($enAttente['total_ttc'], 2, ',', ' '),
                ))
                ->color($enAttente['ventes'] === 0 && $enAttente['avoirs'] === 0 ? 'gray' : 'warning'),
        ];
    }
}
