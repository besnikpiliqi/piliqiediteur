<?php

namespace App\Filament\App\Widgets;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ChosesAFaireDuMagasin;
use App\Domain\Tenancy\Support\ChoseAFaire;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * L'« À faire » du tableau de bord du magasin, juste après la bienvenue
 * (demande de l'éditeur du 26/10/2026) : tout ce qui reste en attente dans
 * ce magasin, en deux parties, « À faire » et « À surveiller », chaque
 * chose avec son nombre, sa phrase et son bouton (voir
 * ChosesAFaireDuMagasin). Il remplace l'alerte des factures à encaisser,
 * la tuile du stock négatif, celle des pièces du fournisseur manquantes
 * et le nombre jaune du menu des factures : le tableau de bord est le
 * seul endroit où l'on voit ce qui reste à faire (règle 7). Quand rien
 * n'attend, il le dit.
 *
 * Un compte d'un cabinet lié et un expert-comptable de la société ne le
 * voient pas (règles 3 et 9).
 */
class AFaireApercu extends Widget
{
    protected static ?int $sort = -2;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    protected static string $view = 'filament.app.widgets.a-faire-apercu';

    public static function canView(): bool
    {
        $compte = auth()->user();

        return $compte instanceof User && ! $compte->entreParLesAutorisationsDUnCabinet() && Filament::getTenant() instanceof Magasin;
    }

    /**
     * @return array{parties: array{'À faire': list<ChoseAFaire>, 'À surveiller': list<ChoseAFaire>}}
     */
    protected function getViewData(): array
    {
        $choses = ChosesAFaireDuMagasin::pour(CompteConnecte::exiger(), ValeursLues::instance(Filament::getTenant(), Magasin::class));

        return ['parties' => ['À faire' => $choses['a_faire'], 'À surveiller' => $choses['a_surveiller']]];
    }
}
