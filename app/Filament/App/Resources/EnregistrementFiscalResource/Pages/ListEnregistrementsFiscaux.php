<?php

namespace App\Filament\App\Resources\EnregistrementFiscalResource\Pages;

use App\Domain\CaisseSecurisee\Actions\ExporterJournalDesEvenementsAction;
use App\Domain\CaisseSecurisee\Exceptions\RefusFiscalException;
use App\Filament\App\Resources\EnregistrementFiscalResource;
use App\Filament\App\Resources\EnregistrementFiscalResource\Widgets\ConformiteApercu;
use App\Filament\Concerns\GereLesExceptionsMetier;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\ListRecords;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListEnregistrementsFiscaux extends ListRecords
{
    use GereLesExceptionsMetier;

    protected static string $resource = EnregistrementFiscalResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ConformiteApercu::class,
        ];
    }

    /**
     * « Exporter le journal (CSV) » : le journal des événements de la
     * période choisie, que l'export inscrit d'abord au journal (JET 110,
     * voir ExporterJournalDesEvenementsAction). Ouvert à qui consulte la
     * caisse sécurisée (voir EnregistrementFiscalPolicy) : de quoi remettre
     * le journal à l'administration.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('exporter_journal')
                ->label('Exporter le journal (CSV)')
                ->icon('heroicon-o-arrow-down-tray')
                ->modalHeading('Exporter le journal des événements')
                ->modalDescription('L\'export s\'inscrit lui-même au journal des événements du magasin.')
                ->modalSubmitActionLabel('Exporter')
                ->form([
                    DatePicker::make('du')->label('Du')->required()->default(now()->startOfMonth()->toDateString()),
                    DatePicker::make('au')->label('Au')->required()->afterOrEqual('du')->default(now()->toDateString()),
                ])
                ->authorize(static fn (): bool => EnregistrementFiscalResource::canViewAny())
                ->action(function (array $data): ?StreamedResponse {
                    try {
                        $ecrire = app(ExporterJournalDesEvenementsAction::class)->handle($data['du'], $data['au']);
                    } catch (LogicException|RefusFiscalException $refus) {
                        static::notifierLeRefus($refus);

                        return null;
                    }

                    return response()->streamDownload(
                        static fn () => $ecrire(fopen('php://output', 'wb')),
                        "journal-des-evenements-du-{$data['du']}-au-{$data['au']}.csv",
                        ['Content-Type' => 'text/csv; charset=UTF-8'],
                    );
                }),
        ];
    }
}
