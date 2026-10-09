<?php

namespace App\Filament\App\Widgets;

use App\Domain\Facturation\Enums\StatutAvoir;
use App\Domain\Facturation\Models\Avoir;
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
 *
 * Net des avoirs émis sur la période : une vente émise ne disparaît
 * jamais, sa correction est un avoir qui se déduit le jour où il est émis
 * (comme dans la clôture de caisse, voir CloturerCaisseAction).
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
        $calculateur = app(CalculateurLigne::class);
        $totauxFactures = Facture::query()->emises()->whereBetween('date_emission', [$debut, $fin])->pluck('total_ttc');
        $totauxAvoirs = Avoir::query()->where('statut', StatutAvoir::Emise)->whereBetween('date_emission', [$debut, $fin])->pluck('total_ttc');

        $chiffreAffaires = $calculateur->soustraire($calculateur->sommer($totauxFactures), $calculateur->sommer($totauxAvoirs));

        return Stat::make($label, number_format($chiffreAffaires, 2, ',', ' ').' € TTC')
            ->description($this->description($totauxFactures->count(), $totauxAvoirs->count()))
            ->descriptionIcon('heroicon-m-document-currency-euro')
            ->color('success');
    }

    private function description(int $nombreFactures, int $nombreAvoirs): string
    {
        $factures = $nombreFactures > 1 ? "{$nombreFactures} factures émises" : "{$nombreFactures} facture émise";

        return match (true) {
            $nombreAvoirs > 1 => "{$factures}, {$nombreAvoirs} avoirs déduits",
            $nombreAvoirs === 1 => "{$factures}, 1 avoir déduit",
            default => $factures,
        };
    }
}
