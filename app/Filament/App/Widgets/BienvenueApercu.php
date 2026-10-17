<?php

namespace App\Filament\App\Widgets;

use App\Domain\Tenancy\Models\Magasin;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * La bienvenue, en tête du tableau de bord du magasin : « Bonjour » et le
 * nom de la personne, puis son rôle dans le magasin ouvert (voir
 * User::libelleDuRoleDans() : « Vendeur · Boutique Lyon 1 »,
 * « Propriétaire de Ma Boutique SAS », « Administrateur »,
 * « Expert-comptable », suivi de « (démonstration) » dans le magasin de
 * démonstration), et le bouton de déconnexion. Elle remplace la bienvenue
 * de Filament, qui ne disait que le nom.
 */
class BienvenueApercu extends Widget
{
    protected static ?int $sort = -3;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static string $view = 'filament.app.widgets.bienvenue-apercu';

    /**
     * @return array{compte: User, role: string}
     */
    protected function getViewData(): array
    {
        /** @var User $compte */
        $compte = auth()->user();
        /** @var Magasin $magasin */
        $magasin = Filament::getTenant();

        return [
            'compte' => $compte,
            'role' => $compte->libelleDuRoleDans($magasin),
        ];
    }
}
