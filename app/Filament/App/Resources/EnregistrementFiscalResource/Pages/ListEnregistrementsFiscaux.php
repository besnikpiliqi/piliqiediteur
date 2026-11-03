<?php

namespace App\Filament\App\Resources\EnregistrementFiscalResource\Pages;

use App\Domain\CaisseSecurisee\Actions\ExporterJournalDesEvenementsAction;
use App\Domain\CaisseSecurisee\Actions\VerifierIntegriteAction;
use App\Domain\CaisseSecurisee\Exceptions\RefusFiscalException;
use App\Domain\CaisseSecurisee\Services\AnomalieFiscale;
use App\Domain\CaisseSecurisee\Services\RapportIntegrite;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\EnregistrementFiscalResource;
use App\Filament\App\Resources\EnregistrementFiscalResource\Widgets\ConformiteApercu;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\PrendLeCalendrierDuMagasin;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Auth\Access\AuthorizationException;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListEnregistrementsFiscaux extends ListRecords
{
    use GereLesExceptionsMetier, PrendLeCalendrierDuMagasin;

    protected static string $resource = EnregistrementFiscalResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            ConformiteApercu::class,
        ];
    }

    /**
     * « Vérifier l'intégrité » : relit le registre fiscal du magasin
     * (chaînes signées, documents, numérotation) et inscrit la vérification
     * au journal des événements (JET 280, et 90 ou 95 pour chaque anomalie,
     * voir VerifierIntegriteAction), depuis la dernière vérification ou,
     * sur demande, depuis le premier enregistrement.
     *
     * « Exporter le journal (CSV) » : le journal des événements de la
     * période choisie, que l'export inscrit d'abord au journal (JET 110,
     * voir ExporterJournalDesEvenementsAction).
     *
     * L'une et l'autre sont ouvertes à qui consulte la caisse sécurisée
     * (voir EnregistrementFiscalPolicy) : de quoi montrer ou remettre à
     * l'administration l'état du registre et son journal.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('verifier_integrite')
                ->label('Vérifier l\'intégrité')
                ->icon('heroicon-o-shield-check')
                ->modalHeading('Vérifier l\'intégrité de la caisse sécurisée')
                ->modalDescription('La vérification relit le registre fiscal du magasin : chaînes signées, documents et numérotation. Elle s\'inscrit elle-même au journal des événements, avec chaque anomalie relevée.')
                ->modalSubmitActionLabel('Vérifier')
                ->form([
                    Toggle::make('complet')
                        ->label('Vérification complète')
                        ->helperText('Tout le registre depuis son premier enregistrement, au lieu de ce qui s\'y est inscrit depuis la dernière vérification : plus long.')
                        ->default(false),
                ])
                ->authorize(static fn (): bool => EnregistrementFiscalResource::canViewAny())
                ->action(function (array $data): void {
                    try {
                        $rapport = app(VerifierIntegriteAction::class)->handle(
                            auth()->user(),
                            app(TenantContext::class)->magasin(),
                            (bool) ($data['complet'] ?? false),
                        );
                    } catch (LogicException|AuthorizationException|RefusFiscalException $refus) {
                        static::notifierLeRefus($refus);

                        return;
                    }

                    static::notifierLeRapport($rapport);
                }),
            Actions\Action::make('exporter_journal')
                ->label('Exporter le journal (CSV)')
                ->icon('heroicon-o-arrow-down-tray')
                ->modalHeading('Exporter le journal des événements')
                ->modalDescription('L\'export s\'inscrit lui-même au journal des événements du magasin.')
                ->modalSubmitActionLabel('Exporter')
                ->form([
                    DatePicker::make('du')->label('Du')->required()->default(fn (): string => Calendrier::premierJourDuMois(self::calendrierDuMagasin()->aujourdhui())),
                    DatePicker::make('au')->label('Au')->required()->afterOrEqual('du')->default(fn (): string => self::calendrierDuMagasin()->aujourdhui()),
                ])
                ->authorize(static fn (): bool => EnregistrementFiscalResource::canViewAny())
                ->action(function (array $data): ?StreamedResponse {
                    try {
                        $ecrire = app(ExporterJournalDesEvenementsAction::class)->handle(auth()->user(), $data['du'], $data['au']);
                    } catch (LogicException|AuthorizationException|RefusFiscalException $refus) {
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

    /**
     * Le résultat de la vérification : le nombre d'enregistrements
     * vérifiés, ou le nombre de ses anomalies et les premières d'entre
     * elles (le journal des événements en garde davantage, voir
     * RapportIntegrite::ANOMALIES_RETENUES), qui restent affichées jusqu'à
     * ce qu'on les ferme.
     */
    protected static function notifierLeRapport(RapportIntegrite $rapport): void
    {
        $verification = $rapport->complet ? 'complète' : 'depuis la dernière vérification';

        if ($rapport->estConforme()) {
            Notification::make()
                ->title('Intégrité vérifiée : aucune anomalie')
                ->body(sprintf('%d enregistrement(s) vérifié(s), vérification %s.', $rapport->nombreDEnregistrementsVerifies(), $verification))
                ->success()
                ->send();

            return;
        }

        $premieres = array_map(static fn (AnomalieFiscale $anomalie): string => e($anomalie->message), array_slice($rapport->anomalies(), 0, 5));

        Notification::make()
            ->title(sprintf('Intégrité : %d anomalie(s) relevée(s)', $rapport->nombreDAnomalies()))
            ->body(implode('<br>', [...$premieres, e("Vérification {$verification}. Les anomalies sont inscrites au journal des événements.")]))
            ->danger()
            ->persistent()
            ->send();
    }
}
