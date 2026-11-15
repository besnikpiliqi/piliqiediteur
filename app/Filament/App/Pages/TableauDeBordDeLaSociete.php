<?php

namespace App\Filament\App\Pages;

use App\Domain\Charges\Services\ChargesAFaire;
use App\Domain\Charges\Services\DroitsSurLesCharges;
use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * « Tableau de bord de la société », dans le panneau des magasins, pour
 * l'expert-comptable que la société a déclaré par le SIREN de son cabinet
 * (décision de l'éditeur du 14/11/2026) : les chiffres clés et les
 * graphiques des magasins réels de sa déclaration, selon les
 * autorisations qu'elle lui accorde (voir GrilleDesGraphiques et
 * PerimetreDesGraphiques::delExpertComptable()), en lecture seule. Elle ne
 * s'ouvre qu'à lui, dans un magasin que sa déclaration lui ouvre, comme
 * « La société » (voir LaSociete).
 */
class TableauDeBordDeLaSociete extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Tableau de bord de la société';

    protected static ?string $title = 'Tableau de bord de la société';

    protected static ?string $slug = 'tableau-de-bord-de-la-societe';

    protected static ?int $navigationSort = 89;

    protected static string $view = 'filament.app.pages.tableau-de-bord-de-la-societe';

    /**
     * Le nombre de charges de la société qu'il a à valider (voir
     * ChargesAFaire), quand sa déclaration lui accorde les charges.
     */
    public function chargesAValider(): int
    {
        $compte = auth()->user();
        $magasin = Filament::getTenant();
        $acces = $compte instanceof User && $magasin instanceof Magasin ? app(DroitsSurLesCharges::class)->pour($compte, $magasin->societe) : null;

        return $acces === null || ! $acces->valide() ? 0 : app(ChargesAFaire::class)->compter($acces)['a_valider'];
    }

    public static function canAccess(): bool
    {
        $compte = auth()->user();
        $magasin = Filament::getTenant();

        return $compte instanceof User && $magasin instanceof Magasin
            && $compte->estExpertComptable() && $compte->estActif()
            && $compte->peutOuvrir($magasin);
    }
}
