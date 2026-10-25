<?php

namespace App\Filament\App\Resources\ClotureCaissePersonnelleResource\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Facturation\Actions\FermerSaCaisseAction;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Domain\Facturation\Services\VerificateurCaissesPersonnelles;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource\Widgets\CaissesOuvertesDeLEquipeApercu;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\PorteUnJeton;
use App\Models\User;
use Filament\Actions;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * « Ma caisse » ou « Les caisses » selon la personne (voir
 * ClotureCaissePersonnelleResource::libelle()) ; en tête de « Les
 * caisses », les caisses encore ouvertes (voir
 * CaissesOuvertesDeLEquipeApercu).
 *
 * « Fermer ma caisse » : quiconque vend ou encaisse ferme la sienne, comme
 * qui y a encore sa caisse ouverte sans plus y être autorisé à vendre (voir
 * ClotureCaissePersonnellePolicy::fermer()), et voit son relevé avant de le
 * fermer (voir FermerSaCaisseAction::apercu()).
 * « Fermer la caisse d'un vendeur » : qui peut clôturer la journée ferme
 * celle d'un autre membre dont la caisse est encore ouverte, pour lui
 * (voir ClotureCaissePersonnellePolicy::fermerLaCaisseDe()) ; son relevé
 * porte « Fermée par » et son nom. Ce relevé reste aussi confidentiel que
 * dans la liste (voir ClotureCaissePersonnelleResource::getEloquentQuery()) :
 * seul qui voit les relevés de toute l'équipe (cloture_caisse.view_toutes)
 * le voit avant de fermer ; tout autre responsable ferme la caisse sans en
 * voir aucun montant. Chaque fenêtre porte le jeton de sa demande (R3,
 * voir PorteUnJeton) : une fermeture renvoyée après une réponse perdue
 * rend le relevé déjà fermé.
 */
class ListClotureCaissePersonnelles extends ListRecords
{
    use GereLesExceptionsMetier;
    use PorteUnJeton;

    protected static string $resource = ClotureCaissePersonnelleResource::class;

    public function getTitle(): string
    {
        return ClotureCaissePersonnelleResource::libelle();
    }

    protected function getHeaderWidgets(): array
    {
        return [CaissesOuvertesDeLEquipeApercu::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('fermer_ma_caisse')
                ->label('Fermer ma caisse')
                ->icon('heroicon-o-lock-closed')
                ->color('primary')
                ->requiresConfirmation()
                ->modalDescription(fn (): HtmlString => self::recapitulatif(app(FermerSaCaisseAction::class)->apercu(auth()->user()), 'votre'))
                ->form([self::champDuJeton()])
                ->visible(fn () => auth()->user()->can('fermer', ClotureCaissePersonnelle::class))
                ->action(fn (array $data) => static::executerSansCasser(fn () => app(FermerSaCaisseAction::class)->handle(auth()->user(), auth()->user(), $data['jeton']))),
            Actions\Action::make('fermer_la_caisse_d_un_vendeur')
                ->label('Fermer la caisse d\'un vendeur')
                ->icon('heroicon-o-user-minus')
                ->color('gray')
                ->modalHeading('Fermer la caisse d\'un vendeur')
                ->modalSubmitActionLabel('Fermer sa caisse')
                ->form([
                    self::champDuJeton(),
                    Select::make('vendeur_id')
                        ->label('Vendeur')
                        ->options(fn (): array => self::vendeursAvecCaisseOuverte()->pluck('name', 'id')->all())
                        ->required()
                        ->live(),
                    Placeholder::make('releve')
                        ->hiddenLabel()
                        ->visible(fn (Get $get): bool => self::vendeurChoisi($get('vendeur_id')) !== null)
                        ->content(fn (Get $get): HtmlString|string => auth()->user()->can(PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES)
                            ? self::recapitulatif(app(FermerSaCaisseAction::class)->apercu(self::vendeurChoisi($get('vendeur_id'))), 'sa')
                            : 'Sa caisse sera fermée jusqu\'à maintenant. Son relevé ne vous sera pas montré : seuls le vendeur et qui voit les relevés de toute l\'équipe le consultent.'),
                ])
                ->visible(fn (): bool => auth()->user()->can(PermissionRegistry::CLOTURE_CAISSE_GERER))
                ->action(function (array $data): void {
                    $vendeur = self::vendeurChoisi($data['vendeur_id'] ?? null);

                    if ($vendeur === null) {
                        Notification::make()->title('La caisse de ce vendeur n\'est plus ouverte.')->warning()->send();

                        return;
                    }

                    static::executerSansCasser(function () use ($vendeur, $data): void {
                        $releve = app(FermerSaCaisseAction::class)->handle(auth()->user(), $vendeur, $data['jeton']);

                        Notification::make()->title("Caisse de {$releve->vendeur_nom} fermée")->success()->send();
                    });
                }),
        ];
    }

    /**
     * Les autres membres dont la caisse est encore ouverte aujourd'hui :
     * les seuls qu'on peut choisir, et que l'on relit à l'enregistrement.
     *
     * @return Collection<int, User>
     */
    private static function vendeursAvecCaisseOuverte(): Collection
    {
        return app(VerificateurCaissesPersonnelles::class)->vendeursAvecCaisseOuverte(Calendrier::deParis()->aujourdhui())
            ->reject(fn (User $vendeur): bool => $vendeur->is(auth()->user()))
            ->values();
    }

    private static function vendeurChoisi(mixed $vendeurId): ?User
    {
        return filled($vendeurId) ? self::vendeursAvecCaisseOuverte()->firstWhere('id', (int) $vendeurId) : null;
    }

    /**
     * Les quatre rubriques du relevé avant de le fermer (B18, voir
     * ClotureCaissePersonnelle) : le TTC vendu, les encaissements par mode
     * de paiement, le crédit d'avoir utilisé et le reste dû, puis ce que
     * couvre le relevé.
     *
     * @param  'votre'|'sa'  $possessif  « votre » quand on ferme sa propre caisse, « sa » celle d'un vendeur
     */
    private static function recapitulatif(ClotureCaissePersonnelle $releve, string $possessif): HtmlString
    {
        $euros = fn (float|string $montant): string => number_format((float) $montant, 2, ',', ' ').' €';
        $encaissements = $releve->encaissementsEnClair() === [] ? 'aucun' : implode(' ; ', $releve->encaissementsEnClair());
        $ses = $possessif === 'votre' ? 'vos' : 'ses';
        $lignes = [
            'TTC vendu : '.$euros($releve->total_ttc).' ('.$releve->nombre_ventes.($releve->nombre_ventes > 1 ? ' ventes' : ' vente').')',
            'Encaissé : '.$euros($releve->totalEncaisse()).' (par mode : '.$encaissements.')',
            'Crédit d\'avoir utilisé : '.$euros($releve->credit_avoir),
            'Reste dû : '.$euros($releve->reste_du),
            "Ce relevé compte {$ses} ventes et {$ses} paiements qu'aucune fermeture n'a encore comptés, y compris ceux d'un jour précédent. Il part de {$possessif} dernière fermeture de caisse (ou du début de la journée si c'est la première), ou de {$possessif} plus ancienne opération non comptée si elle est antérieure. Ceci ne clôture pas la journée du magasin.",
        ];

        return new HtmlString(implode('<br>', array_map(e(...), $lignes)));
    }
}
