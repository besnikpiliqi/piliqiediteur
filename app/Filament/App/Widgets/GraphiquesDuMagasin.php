<?php

namespace App\Filament\App\Widgets;

use App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord;
use App\Domain\TableauDeBord\Services\PerimetreDesGraphiques;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\GrilleDesGraphiques;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Les chiffres clés et les graphiques du tableau de bord du magasin, sur
 * ce seul magasin, sans filtre de magasin ni graphique qui en compare
 * plusieurs (voir GrilleDesGraphiques) : pour le propriétaire, un
 * administrateur ou un responsable à qui il coche « Tableau de bord : voir
 * les graphiques », un compte du cabinet lié et l'expert-comptable déclaré
 * (voir PerimetreDesGraphiques::voitLesGraphiquesDuMagasin()) ; jamais un
 * vendeur, un observateur, ni dans le magasin de démonstration. Après
 * l'« À faire ».
 */
class GraphiquesDuMagasin extends GrilleDesGraphiques
{
    protected static ?int $sort = -1;

    public static function canView(): bool
    {
        $compte = auth()->user();
        $magasin = Filament::getTenant();

        return $compte instanceof User && $magasin instanceof Magasin && app(PerimetreDesGraphiques::class)->voitLesGraphiquesDuMagasin($compte, $magasin);
    }

    public function mount(string $ecran = 'magasin', ?string $lien = null): void
    {
        parent::mount(EcranDuTableauDeBord::Magasin->value);
    }
}
