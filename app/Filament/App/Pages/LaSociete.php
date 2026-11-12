<?php

namespace App\Filament\App\Pages;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Page;

/**
 * « La société », dans le panneau des magasins, pour l'expert-comptable
 * que la société a déclaré par le SIREN de son cabinet (décision de
 * l'éditeur du 01/11/2026, P10) : son identité légale, son type
 * d'activité et ses réglages fiscaux communs, en lecture seule, comme un
 * cabinet lié les lit dans son espace. Il n'a pas d'espace de cabinet ni
 * d'accès à l'espace de la société : cette page ne s'ouvre qu'à lui, dans
 * un magasin réel de sa société que sa déclaration lui ouvre ; elle ne
 * lit que la société du magasin courant, ses seuls champs montrés.
 */
class LaSociete extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationLabel = 'La société';

    protected static ?string $title = 'La société';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.app.pages.la-societe';

    public static function canAccess(): bool
    {
        $compte = auth()->user();
        $magasin = Filament::getTenant();

        return $compte instanceof User && $magasin instanceof Magasin
            && $compte->estExpertComptable() && $compte->estActif()
            && $compte->peutOuvrir($magasin);
    }

    /**
     * La société du magasin courant, ses seuls champs montrés.
     */
    public function societe(): Societe
    {
        $magasin = ValeursLues::instance(Filament::getTenant(), Magasin::class);

        return Societe::query()->whereKey($magasin->societe_id)->firstOrFail([...Societe::CHAMPS_DE_L_IDENTITE, 'id', 'siren', 'type_activite', 'regime_tva', 'option_debits_tva', 'mois_fin_exercice']);
    }
}
