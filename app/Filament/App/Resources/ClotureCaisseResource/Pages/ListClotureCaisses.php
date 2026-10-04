<?php

namespace App\Filament\App\Resources\ClotureCaisseResource\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Filament\App\Resources\ClotureCaisseResource;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;

class ListClotureCaisses extends ListRecords
{
    protected static string $resource = ClotureCaisseResource::class;

    /**
     * Clôturer la journée n'est jamais un choix de date : le magasin ne
     * clôture que la veille (le seul jour "terminé" en attente), exactement
     * comme le ferait la commande automatique de minuit si le propriétaire
     * ne la déclenche pas lui-même -- voir ClotureAutomatiqueCaisse. Les
     * deux chemins mènent donc toujours au même jour, jamais à un jour
     * choisi arbitrairement.
     */
    protected function getHeaderActions(): array
    {
        $veille = now()->subDay();

        return [
            Actions\Action::make('cloturer')
                ->label('Clôturer une journée')
                ->icon('heroicon-o-lock-closed')
                ->color('primary')
                ->requiresConfirmation()
                ->modalHeading('Clôturer la journée du '.$veille->translatedFormat('d/m/Y').' ?')
                ->modalDescription('Cette action est IRRÉVERSIBLE : une fois clôturée, cette journée ne pourra plus jamais être modifiée, ni clôturée à nouveau.')
                ->modalSubmitActionLabel('Oui, clôturer définitivement')
                ->visible(fn () => auth()->user()->can(PermissionRegistry::CLOTURE_CAISSE_GERER)
                    && ClotureCaisse::query()->whereDate('date_cloture', $veille)->doesntExist())
                ->action(fn () => app(CloturerCaisseAction::class)->handle(Filament::getTenant(), $veille)),
        ];
    }
}
