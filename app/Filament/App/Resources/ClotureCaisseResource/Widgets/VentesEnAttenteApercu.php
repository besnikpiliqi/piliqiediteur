<?php

namespace App\Filament\App\Resources\ClotureCaisseResource\Widgets;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Vit délibérément sous Resources/ClotureCaisseResource/Widgets, PAS sous
 * Filament/App/Widgets (scanné par discoverWidgets() et donc rendu sur le
 * tableau de bord général) : ce widget n'a de sens que sur la liste des
 * clôtures, attaché explicitement via ListClotureCaisses::getHeaderWidgets().
 *
 * Aperçu de ce qui sera regroupé dans la PROCHAINE clôture (toujours la
 * veille -- voir ListClotureCaisses) : mêmes critères que
 * CloturerCaisseAction, ventes moins avoirs du jour, pour que le gérant
 * voie directement sur la page ce qui va être clôturé, pas seulement dans
 * la modale de confirmation au moment de cliquer.
 */
class VentesEnAttenteApercu extends BaseWidget
{
    public static function canView(): bool
    {
        return auth()->user()->can(PermissionRegistry::CLOTURE_CAISSE_GERER);
    }

    protected function getStats(): array
    {
        $veille = now()->subDay();

        $ventes = Facture::query()->enAttenteDeCloture($veille)->get();
        $avoirs = Avoir::query()->enAttenteDeCloture($veille)->get();
        $calculateur = app(CalculateurLigne::class);
        $totalNet = $calculateur->deduire($calculateur->cumuler($ventes), $calculateur->cumuler($avoirs))['total_ttc'];

        return [
            Stat::make('Ventes en attente de clôture', (string) $ventes->count())
                ->description(sprintf(
                    'Journée du %s · %s%s € au total',
                    $veille->translatedFormat('d/m/Y'),
                    $avoirs->isEmpty() ? '' : "{$avoirs->count()} avoir(s) déduit(s) · ",
                    number_format($totalNet, 2, ',', ' '),
                ))
                ->color($ventes->isEmpty() && $avoirs->isEmpty() ? 'gray' : 'warning'),
        ];
    }
}
