<?php

namespace App\Filament\App\Widgets;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Produit;
use App\Filament\App\Resources\ProduitResource;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Le compteur, sur le tableau de bord, des produits du catalogue que des
 * ventes sans stock (R1) ont laissés en stock négatif : leur quantité
 * reste à régulariser par un achat, une réception ou un inventaire. Il ne
 * s'affiche que s'il y en a, à qui voit les produits, et mène à leur liste
 * filtrée (voir ProduitResource).
 */
class StockNegatifApercu extends BaseWidget
{
    public static function canView(): bool
    {
        return (auth()->user()?->can(PermissionRegistry::PRODUIT_VIEW) ?? false) && Produit::query()->enStockNegatif()->exists();
    }

    protected function getStats(): array
    {
        $nombre = Produit::query()->enStockNegatif()->count();

        return [
            Stat::make('Stock négatif', (string) $nombre)
                ->description($nombre > 1 ? "{$nombre} produits à régulariser" : '1 produit à régulariser')
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->url(ProduitResource::getUrl('index', ['tableFilters' => ['statut_stock' => ['value' => 'negatif']]]))
                ->color('danger'),
        ];
    }
}
