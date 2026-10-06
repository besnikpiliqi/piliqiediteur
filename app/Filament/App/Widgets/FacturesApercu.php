<?php

namespace App\Filament\App\Widgets;

use App\Domain\Facturation\Enums\StatutFacture;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use Carbon\Carbon;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Chiffre d'affaires facturé par période (semaine/mois/année en cours),
 * affiché en trois tuiles simultanées plutôt qu'avec un sélecteur : cette
 * version de Filament (3.3) ne propose un filtre de période intégré que
 * sur ChartWidget, pas sur StatsOverviewWidget. Montrer les trois d'un
 * coup évite de toute façon un clic pour comparer les périodes entre
 * elles. Scopé automatiquement au magasin actif via MagasinScope.
 */
class FacturesApercu extends BaseWidget
{
    protected function getStats(): array
    {
        return [
            $this->statPourPeriode('Cette semaine', now()->startOfWeek(), now()->endOfWeek()),
            $this->statPourPeriode('Ce mois-ci', now()->startOfMonth(), now()->endOfMonth()),
            $this->statPourPeriode('Cette année', now()->startOfYear(), now()->endOfYear()),
        ];
    }

    private function statPourPeriode(string $label, Carbon $debut, Carbon $fin): Stat
    {
        $facturesDeLaPeriode = Facture::query()
            ->whereNotIn('statut', [StatutFacture::Brouillon, StatutFacture::Annulee])
            ->whereBetween('date_emission', [$debut, $fin]);

        $totalTtc = app(CalculateurLigne::class)->sommer((clone $facturesDeLaPeriode)->pluck('total_ttc'));
        $nombreFactures = (clone $facturesDeLaPeriode)->count();

        return Stat::make($label, number_format($totalTtc, 2, ',', ' ').' €')
            ->description($nombreFactures > 1 ? "{$nombreFactures} factures émises" : "{$nombreFactures} facture émise")
            ->descriptionIcon('heroicon-m-document-currency-euro')
            ->color('success');
    }
}
