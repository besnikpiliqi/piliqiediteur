<?php

namespace App\Filament\App\Resources;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\RefuserDevisAction;
use App\Domain\Facturation\Actions\SupprimerDevisAction;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Models\Devis;
use App\Domain\Facturation\Services\PiedDePage;
use App\Filament\App\Resources\DevisResource\Pages;
use App\Filament\Concerns\AjouteDesLignesDeVente;
use App\Filament\Concerns\ChoisitLaNatureDeLOperation;
use App\Filament\Concerns\EnvoieLesDocuments;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\SupprimeEnMasseLigneParLigne;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DevisResource extends Resource
{
    use AjouteDesLignesDeVente;
    use ChoisitLaNatureDeLOperation;
    use EnvoieLesDocuments;
    use GereLesExceptionsMetier;
    use SupprimeEnMasseLigneParLigne;

    protected static ?string $model = Devis::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Ventes';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'devis';

    protected static ?string $pluralModelLabel = 'devis';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Devis')->schema([
                Select::make('client_id')
                    ->label('Client')
                    // Un client archivé ne reçoit plus de nouveau document,
                    // même si ceux qu'il a déjà le gardent (voir
                    // Devis::client() et AppServiceProvider::limiterLesChoixAuMagasin()).
                    ->relationProposable('client', 'nom', ClientResource::colonnesDeRecherche())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Get $get, Set $set) => self::ajusterLaNatureAuClient($get, $set))
                    ->helperText(fn (Get $get) => ClientResource::soldeCrediteurHelperText($get('client_id')))
                    ->createOptionForm(ClientResource::creationRapideSchema())
                    ->createOptionModalHeading('Nouveau client')
                    ->createOptionAction(fn (Action $action) => $action->visible(auth()->user()->can(PermissionRegistry::CLIENT_CREATE)))
                    ->createOptionUsing(fn (array $data): int => ClientResource::creerUnClient($data))
                    ->suffixAction(ClientResource::actionChercherUnClient()),
                DatePicker::make('date_validite')->label('Valide jusqu\'au'),
                self::champNatureDeLOperation(),
                Textarea::make('conditions_paiement')->label('Conditions de paiement')->maxLength(PiedDePage::CARACTERES_DES_CONDITIONS_DE_PAIEMENT)->columnSpanFull(),
                Textarea::make('notes')->columnSpanFull(),
            ])->columns(2),

            self::sectionLivraison(),

            Section::make('Lignes')->schema([
                // La page de modification n'est ouverte qu'à un devis
                // modifiable (voir Devis::estModifiable() et
                // DevisPolicy::update()), lignes comprises.
                ...self::lignesDeVente(),
                self::totauxDuDocument(parTaux: true),
            ]),
        ]);
    }

    /**
     * Un devis refusé, expiré ou transformé en facture n'est plus
     * modifiable : sans cette fiche, ses lignes et ses totaux ne seraient
     * plus visibles nulle part. Mêmes colonnes que la facture (voir
     * FactureResource::lignesEntry()).
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Devis')->schema([
                TextEntry::make('numero')->label('Numéro')->placeholder('Brouillon (pas encore envoyé)'),
                TextEntry::make('statut')->badge(),
                TextEntry::make('client.nom')->label('Client'),
                // Repassé en brouillon par une modification, un devis garde
                // la date de son dernier envoi (voir ModifierDevisAction).
                TextEntry::make('date_emission')->label('Dernier envoi le')->dateTime('d/m/Y')->placeholder('—'),
                TextEntry::make('date_validite')->label('Valide jusqu\'au')->jour()->placeholder('—'),
                FactureResource::natureEntry(),
            ])->columns(3),

            FactureResource::livraisonSection(),

            InfolistSection::make('Lignes')->schema([
                FactureResource::lignesEntry($infolist->getRecord()),
            ]),

            InfolistSection::make('Totaux')->schema([
                TextEntry::make('total_ht')->label('Total HT')->money('EUR'),
                TextEntry::make('total_tva')->label('Total TVA')->money('EUR'),
                TextEntry::make('total_ttc')->label('Total TTC')->money('EUR'),
                FactureResource::ventilationTvaEntry(),
            ])->columns(3),

            self::envoisSection(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->placeholder('Brouillon')->rechercheSansAccents(),
                Tables\Columns\TextColumn::make('client.nom')->label('Client')->rechercheSansAccents(),
                Tables\Columns\TextColumn::make('statut')->badge(),
                Tables\Columns\TextColumn::make('total_ttc')->label('Total TTC')->money('EUR')->sortable(),
                Tables\Columns\TextColumn::make('date_validite')->label('Validité')->jour(),
                Tables\Columns\TextColumn::make('created_at')->label('Créé le')->dateTime('d/m/Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('statut')->options(StatutDevis::class),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                // Affichée et autorisée par DevisPolicy::update() (voir
                // ListRecords::configureEditAction()) : un brouillon, ou un
                // devis envoyé ou accepté pas encore transformé en facture.
                Tables\Actions\EditAction::make(),
                self::telechargerLePdf(Tables\Actions\Action::make('telecharger_pdf')),
                self::envoyerParEmail(Tables\Actions\Action::make('envoyer_par_email')),
                // Remis en main propre, par exemple : le devis part sans
                // e-mail, avec le numéro qu'il reçoit, ou celui qu'il a gardé
                // s'il a été modifié depuis son envoi (voir
                // EmettreDevisAction).
                Tables\Actions\Action::make('envoyer')
                    ->label(fn (Devis $record): string => $record->numero === null ? 'Numéroter sans e-mail' : 'Marquer envoyé sans e-mail')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription(fn (Devis $record): string => $record->numero === null
                        ? 'Le devis reçoit son numéro et passe au statut « Envoyé », sans e-mail : pour un devis remis en main propre, par exemple.'
                        : "Le devis garde son numéro {$record->numero} et repasse au statut « Envoyé », sans e-mail : pour un devis remis en main propre, par exemple.")
                    ->form([self::champDuJeton()])
                    ->visible(fn (Devis $record) => $record->statut === StatutDevis::Brouillon && auth()->user()->can('envoyer', $record))
                    ->action(fn (Devis $record, array $data) => static::executerSansCasser(fn () => app(EmettreDevisAction::class)->handle(auth()->user(), $record, $data['jeton']))),
                // Masquée une fois la date de validité dépassée (voir
                // DevisPolicy::accepter()).
                Tables\Actions\Action::make('accepter')
                    ->label('Accepter')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Devis $record) => $record->statut === StatutDevis::Envoye && auth()->user()->can('accepter', $record))
                    ->action(fn (Devis $record) => static::executerSansCasser(fn () => app(AccepterDevisAction::class)->handle(auth()->user(), $record))),
                Tables\Actions\Action::make('refuser')
                    ->label('Refuser')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Devis $record) => $record->statut === StatutDevis::Envoye && auth()->user()->can('refuser', $record))
                    ->action(fn (Devis $record) => static::executerSansCasser(fn () => app(RefuserDevisAction::class)->handle(auth()->user(), $record))),
                Tables\Actions\Action::make('convertir')
                    ->label('Convertir en facture')
                    ->icon('heroicon-o-document-currency-euro')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription('Une nouvelle facture brouillon sera créée avec les mêmes lignes que ce devis.')
                    ->visible(fn (Devis $record) => $record->statut === StatutDevis::Accepte
                        && $record->factures()->doesntExist()
                        && auth()->user()->can('convertir', $record))
                    ->action(fn (Devis $record) => static::executerSansCasser(fn () => app(CreerFactureDepuisDevisAction::class)->handle(auth()->user(), $record))),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    static::suppressionEnMasse(fn (Devis $devis) => app(SupprimerDevisAction::class)->handle(auth()->user(), $devis)),
                ]),
            ]);
    }

    /**
     * « Supprimer » un brouillon jamais numéroté, depuis la page de
     * modification : par l'action du domaine, qui relit sous verrou le
     * magasin, l'auteur et le devis (voir SupprimerDevisAction) ; numéroté
     * par une autre requête depuis l'ouverture de la page, ou dans un
     * magasin fermé entre-temps, il reste, et la page dit pourquoi. Un
     * devis supprimé par une autre requête depuis l'ouverture de la page
     * n'y est plus : la page revient à la liste, qui le dit (voir
     * RevientALaListeSiLeDocumentEstSupprime).
     */
    public static function actionSupprimer(DeleteAction $action): DeleteAction
    {
        return $action->using(fn (Devis $record): bool => static::executerOuInterrompre(function () use ($record): bool {
            app(SupprimerDevisAction::class)->handle(auth()->user(), $record);

            return true;
        }));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDevis::route('/'),
            'create' => Pages\CreateDevis::route('/create'),
            'view' => Pages\ViewDevis::route('/{record}'),
            'edit' => Pages\EditDevis::route('/{record}/edit'),
        ];
    }
}
