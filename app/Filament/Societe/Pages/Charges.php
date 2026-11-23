<?php

namespace App\Filament\Societe\Pages;

use App\Domain\Charges\Services\DroitsSurLesCharges;
use App\Domain\Tenancy\Models\Societe;
use App\Filament\PageDesCharges;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * « Charges », dans l'espace de la société (décision de l'éditeur du
 * 14/11/2026, lot CHARGES) : pour le propriétaire, et pour l'administrateur
 * à qui il coche « Charges : ajouter », sur sa portée (voir
 * DroitsSurLesCharges et PageDesCharges) ; jamais dans l'espace d'un
 * cabinet d'expertise comptable, qui n'a pas de charges dans PILIQI.
 */
class Charges extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationLabel = 'Charges';

    protected static ?string $title = 'Charges';

    protected static ?string $slug = 'charges';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.charges.page';

    public static function canAccess(): bool
    {
        $compte = auth()->user();
        $societe = Filament::getTenant();

        return $compte instanceof User && $societe instanceof Societe
            && $compte->societe_id === $societe->getKey() && $compte->peutAdministrer()
            && app(DroitsSurLesCharges::class)->pour($compte, $societe, pourValider: false) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['ecran' => PageDesCharges::SOCIETE, 'lien' => null];
    }
}
