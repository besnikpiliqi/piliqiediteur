<?php

namespace App\Filament\App\Resources\ClotureCaisseResource\Widgets;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
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
 * CloturerCaisseAction, pour que le gérant voie directement sur la page ce
 * qui va être clôturé, pas seulement dans la modale de confirmation au
 * moment de cliquer.
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

        $ventes = Facture::query()
            ->whereHas('client', fn ($query) => $query->where('type_client', TypeClient::Particulier))
            ->whereDate('date_emission', $veille)
            ->whereNotIn('statut', [StatutFacture::Brouillon, StatutFacture::Annulee])
            ->whereNull('cloture_caisse_id')
            ->get();

        return [
            Stat::make('Ventes en attente de clôture', (string) $ventes->count())
                ->description(sprintf(
                    'Journée du %s · %s € au total',
                    $veille->translatedFormat('d/m/Y'),
                    number_format($ventes->sum(fn (Facture $facture) => (float) $facture->total_ttc), 2, ',', ' '),
                ))
                ->color($ventes->isEmpty() ? 'gray' : 'warning'),
        ];
    }
}
