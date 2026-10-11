<?php

namespace App\Filament\Societe\Pages;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\Societe;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Le tableau de bord de la société : où en est sa vérification, ce qui
 * manque à son identité pour facturer un professionnel, et ses magasins,
 * chacun avec son SIRET, son établissement (vérifié ou à vérifier) et son
 * état. Ses magasins se lisent par la société (Societe::magasins()) : ceux
 * d'aucune autre société.
 */
class TableauDeBord extends Dashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $navigationLabel = 'Tableau de bord';

    protected static string $view = 'filament.societe.pages.tableau-de-bord';

    public static function canAccess(): bool
    {
        $societe = Filament::getTenant();

        return $societe instanceof Societe && (auth()->user()?->can('view', $societe) ?? false);
    }

    public function getTitle(): string|Htmlable
    {
        return $this->societe()->raison_sociale ?? 'Votre société';
    }

    public function societe(): Societe
    {
        /** @var Societe */
        return Filament::getTenant();
    }

    /**
     * L'état de la vérification de la société, tel que l'écran le dit.
     */
    public function etatDeLaVerification(): string
    {
        $societe = $this->societe();

        return match (true) {
            $societe->estRefusee() => 'L\'inscription de votre société a été refusée.',
            $societe->estValidee() => 'Votre société est validée depuis le '.$societe->validee_le->setTimezone(config('app.timezone'))->format('d/m/Y').'.',
            default => 'Votre société est en cours de vérification : la vente, les e-mails aux clients, les PDF et les transmissions sont désactivés jusqu\'à sa validation. Vous pouvez déjà configurer vos magasins : catalogue, équipe, paramètres et achats.',
        };
    }

    /**
     * @return Collection<int, Magasin>
     */
    public function magasins(): Collection
    {
        return $this->societe()->magasins()->orderBy('nom')->orderBy('id')->get();
    }
}
