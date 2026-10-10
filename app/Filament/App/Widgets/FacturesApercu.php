<?php

namespace App\Filament\App\Widgets;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Services\ChiffreDAffaires;
use Carbon\CarbonInterface;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Le chiffre d'affaires de la semaine, du mois et de l'année en cours, net
 * des avoirs, en HT avec le TTC à côté (B17, voir ChiffreDAffaires), en
 * trois tuiles simultanées : cette version de Filament (3.3) ne propose
 * un filtre de période intégré que sur ChartWidget, et voir les trois
 * d'un coup évite un clic pour comparer les périodes.
 *
 * Confidentialité entre vendeurs (B16) : avec l'autorisation « voir
 * celles de toute l'équipe », le chiffre d'affaires du magasin ; sinon
 * « Mes ventes », les seules ventes de l'utilisateur. Réservé à qui voit
 * des factures.
 */
class FacturesApercu extends BaseWidget
{
    public static function canView(): bool
    {
        return auth()->user()->can(PermissionRegistry::FACTURE_VIEW);
    }

    protected function getHeading(): string
    {
        return $this->voitLesVentesDeToutLEquipe() ? 'Chiffre d\'affaires' : 'Mes ventes';
    }

    protected function getStats(): array
    {
        return [
            $this->statPourPeriode('Cette semaine', now()->startOfWeek(), now()->endOfWeek()),
            $this->statPourPeriode('Ce mois-ci', now()->startOfMonth(), now()->endOfMonth()),
            $this->statPourPeriode('Cette année', now()->startOfYear(), now()->endOfYear()),
        ];
    }

    private function voitLesVentesDeToutLEquipe(): bool
    {
        return auth()->user()->can(PermissionRegistry::FACTURE_VIEW_TOUTES);
    }

    /**
     * « 133,33 € HT », puis « 160,00 € TTC · 2 factures, 1 avoir » : le
     * nombre d'avoirs n'apparaît que s'il y en a.
     */
    private function statPourPeriode(string $libelle, CarbonInterface $debut, CarbonInterface $fin): Stat
    {
        $net = app(ChiffreDAffaires::class)->netSurPeriode($debut, $fin, $this->voitLesVentesDeToutLEquipe() ? null : auth()->user());
        $euros = fn (float $montant): string => number_format($montant, 2, ',', ' ').' €';
        $nombres = $net['factures'].($net['factures'] > 1 ? ' factures' : ' facture')
            .($net['avoirs'] > 0 ? ', '.$net['avoirs'].($net['avoirs'] > 1 ? ' avoirs' : ' avoir') : '');

        return Stat::make($libelle, $euros($net['ht']).' HT')
            ->description($euros($net['ttc']).' TTC · '.$nombres)
            ->descriptionIcon('heroicon-m-document-currency-euro')
            ->color('success');
    }
}
