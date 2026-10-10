<?php

namespace App\Filament\App\Resources\ClotureCaissePersonnelleResource\Pages;

use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\HtmlString;

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
                ->modalDescription(fn (): HtmlString => self::recapitulatif(app(FermerSaCaisseAction::class)->apercu(auth()->user())))
                ->visible(fn () => auth()->user()->can('fermer', ClotureCaissePersonnelle::class))
                ->action(fn () => app(FermerSaCaisseAction::class)->handle(auth()->user())),
        ];
    }

    /**
     * Les quatre rubriques du relevé avant de le fermer (B18, voir
     * ClotureCaissePersonnelle) : le TTC vendu, les encaissements par mode
     * de paiement, le crédit d'avoir utilisé et le reste dû, puis ce que
     * couvre le relevé.
     */
    private static function recapitulatif(ClotureCaissePersonnelle $releve): HtmlString
    {
        $euros = fn (float|string $montant): string => number_format((float) $montant, 2, ',', ' ').' €';
        $encaissements = $releve->encaissementsEnClair() === [] ? 'aucun' : implode(' ; ', $releve->encaissementsEnClair());
        $lignes = [
            'TTC vendu : '.$euros($releve->total_ttc).' ('.$releve->nombre_ventes.($releve->nombre_ventes > 1 ? ' ventes' : ' vente').')',
            'Encaissé : '.$euros($releve->totalEncaisse()).' (par mode : '.$encaissements.')',
            'Crédit d\'avoir utilisé : '.$euros($releve->credit_avoir),
            'Reste dû : '.$euros($releve->reste_du),
            'Ce relevé compte vos ventes et vos paiements qu\'aucune fermeture n\'a encore comptés, y compris ceux d\'un jour précédent. Il part de votre dernière fermeture de caisse (ou du début de la journée si c\'est la première), ou de votre plus ancienne opération non comptée si elle est antérieure. Ceci ne clôture pas la journée du magasin.',
        ];

        return new HtmlString(implode('<br>', array_map(e(...), $lignes)));
    }
}
