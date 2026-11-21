<?php

namespace App\Filament\App\Pages;

use App\Domain\Charges\Services\DroitsSurLesCharges;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\PageDesCharges;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * « Charges du magasin », dans le panneau du magasin d'un responsable à qui
 * le propriétaire coche « Charges : ajouter » (décision de l'éditeur du
 * 14/11/2026, lot CHARGES) : les charges de son magasin seulement, qu'il
 * voit et ajoute, sans jamais les valider ; rien de « Toute la société »
 * ni d'un autre magasin (voir DroitsSurLesCharges et PageDesCharges).
 */
class ChargesDuMagasin extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationLabel = 'Charges du magasin';

    protected static ?string $title = 'Charges du magasin';

    protected static ?string $slug = 'charges-du-magasin';

    protected static ?int $navigationSort = 88;

    protected static string $view = 'filament.charges.page';

    public static function canAccess(): bool
    {
        $compte = auth()->user();
        $magasin = Filament::getTenant();

        return $compte instanceof User && $magasin instanceof Magasin
            && $compte->estMembre() && $compte->estResponsableDe($magasin)
            && app(DroitsSurLesCharges::class)->pour($compte, $magasin->societe) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['ecran' => PageDesCharges::MAGASIN, 'lien' => null];
    }
}
