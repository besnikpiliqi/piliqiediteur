<?php

namespace App\Filament\App\Pages;

use App\Domain\Charges\Services\DroitsSurLesCharges;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\PageDesCharges;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * « Charges de la société », dans le panneau des magasins, pour
 * l'expert-comptable que la société a déclaré (décision de l'éditeur du
 * 14/11/2026, lot CHARGES), à côté de son « Tableau de bord de la
 * société » : les charges de « Toute la société » et des magasins de
 * sa déclaration, qu'il voit, ajoute, valide, corrige et renvoie, quand la
 * déclaration lui accorde « Charges : voir, ajouter, saisir et valider »
 * (voir DroitsSurLesCharges et PageDesCharges).
 */
class ChargesDeLaSociete extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?string $navigationLabel = 'Charges de la société';

    protected static ?string $title = 'Charges de la société';

    protected static ?string $slug = 'charges-de-la-societe';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.charges.page';

    public static function canAccess(): bool
    {
        $compte = auth()->user();
        $magasin = Filament::getTenant();

        return $compte instanceof User && $magasin instanceof Magasin
            && $compte->estExpertComptable() && $compte->estActif() && $compte->peutOuvrir($magasin)
            && app(DroitsSurLesCharges::class)->pour($compte, $magasin->societe) !== null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return ['ecran' => PageDesCharges::EXPERT_COMPTABLE, 'lien' => null];
    }
}
