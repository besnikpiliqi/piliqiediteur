<?php

namespace App\Filament\App\Resources\ClotureCaisseResource\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Actions\CloturerCaisseAction;
use App\Filament\App\Resources\ClotureCaisseResource;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\ListRecords;

class ListClotureCaisses extends ListRecords
{
    protected static string $resource = ClotureCaisseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('cloturer')
                ->label('Clôturer une journée')
                ->icon('heroicon-o-lock-closed')
                ->color('primary')
                ->form([
                    DatePicker::make('date_cloture')
                        ->label('Journée à clôturer')
                        ->default(now()->subDay())
                        ->maxDate(now())
                        ->required(),
                ])
                ->requiresConfirmation()
                ->modalDescription('Agrège toutes les ventes aux clients particuliers de cette journée en un document fiscal définitif. Une journée ne peut être clôturée qu\'une seule fois.')
                ->visible(fn () => auth()->user()->can(PermissionRegistry::CLOTURE_CAISSE_GERER))
                ->action(fn (array $data) => app(CloturerCaisseAction::class)->handle(Filament::getTenant(), $data['date_cloture'])),
        ];
    }
}
