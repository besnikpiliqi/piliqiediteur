<?php

namespace App\Filament\App\Widgets;

use App\Domain\Achats\Enums\StatutAvoirFournisseur;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Domain\Achats\Models\FactureAchat;
use App\Filament\App\Resources\AvoirFournisseurResource;
use App\Filament\App\Resources\FactureAchatResource;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * « À faire » sur le tableau de bord du magasin (décision de l'éditeur du
 * 03/11/2026, F1 = b) : les factures d'achat et les avoirs fournisseur
 * saisis à la main sans la pièce du fournisseur, marqués « pièce du
 * fournisseur manquante » (voir PiecesDuFournisseur), chacun compté et
 * menant à sa liste filtrée. Seulement à qui voit ces documents, et quand
 * il en manque une ; un avoir n'y compte qu'émis, une facture même en
 * brouillon.
 */
class PiecesDuFournisseurManquantesApercu extends BaseWidget
{
    protected static ?int $sort = -1;

    protected ?string $heading = 'À faire';

    public static function canView(): bool
    {
        return self::stats() !== [];
    }

    protected function getStats(): array
    {
        return self::stats();
    }

    /**
     * @return list<Stat>
     */
    private static function stats(): array
    {
        $utilisateur = auth()->user();
        $stats = [];

        if ($utilisateur?->can('viewAny', FactureAchat::class) && ($factures = FactureAchat::query()->where('piece_manquante', true)->count()) > 0) {
            $stats[] = Stat::make('Pièces du fournisseur manquantes', (string) $factures)
                ->description($factures > 1 ? "{$factures} factures d'achat sans leur pièce" : '1 facture d\'achat sans sa pièce')
                ->descriptionIcon('heroicon-m-paper-clip')
                ->url(FactureAchatResource::getUrl('index', ['tableFilters' => ['piece_manquante' => ['isActive' => true]]]))
                ->color('warning');
        }

        if ($utilisateur?->can('viewAny', AvoirFournisseur::class) && ($avoirs = AvoirFournisseur::query()->where('statut', StatutAvoirFournisseur::Emise)->where('piece_manquante', true)->count()) > 0) {
            $stats[] = Stat::make('Notes d\'avoir du fournisseur manquantes', (string) $avoirs)
                ->description($avoirs > 1 ? "{$avoirs} avoirs fournisseur sans leur pièce" : '1 avoir fournisseur sans sa pièce')
                ->descriptionIcon('heroicon-m-paper-clip')
                ->url(AvoirFournisseurResource::getUrl('index', ['tableFilters' => ['piece_manquante' => ['isActive' => true]]]))
                ->color('warning');
        }

        return $stats;
    }
}
