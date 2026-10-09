<?php

namespace App\Filament\App\Resources\ClotureCaissePersonnelleResource\Pages;

use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListClotureCaissePersonnelles extends ListRecords
{
    protected static string $resource = ClotureCaissePersonnelleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('fermer_ma_caisse')
                ->label('Fermer ma caisse')
                ->icon('heroicon-o-lock-closed')
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription('Enregistre un relevé de vos ventes qu\'aucune fermeture n\'a encore comptées, y compris celles d\'un jour précédent. Il part de votre dernière fermeture de caisse (ou du début de la journée si c\'est la première), ou de votre plus ancienne vente non comptée si elle est antérieure. Ceci ne clôture pas la journée du magasin.')
                ->visible(fn () => auth()->user()->can('fermer', ClotureCaissePersonnelle::class))
                ->action(fn () => app(FermerSaCaisseAction::class)->handle(auth()->user())),
        ];
    }
}
