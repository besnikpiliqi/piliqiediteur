<?php

namespace App\Filament\App\Resources;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Actions\AccepterDevisAction;
use App\Domain\Facturation\Actions\CreerFactureDepuisDevisAction;
use App\Domain\Facturation\Actions\EmettreDevisAction;
use App\Domain\Facturation\Actions\RefuserDevisAction;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Facturation\Models\Devis;
use App\Filament\App\Resources\DevisResource\Pages;
use App\Filament\Concerns\AjouteDesLignesDeVente;
use App\Filament\Concerns\EnvoieLesDocuments;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\SupprimeEnMasseLigneParLigne;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DevisResource extends Resource
{
    use AjouteDesLignesDeVente;
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
                    ->relationProposable('client', 'nom')
                    ->searchable()
                    ->preload()
                    ->required()
                    ->live()
                    ->helperText(fn (Get $get) => ClientResource::soldeCrediteurHelperText($get('client_id')))
                    ->createOptionForm(ClientResource::creationRapideSchema())
                    ->createOptionModalHeading('Nouveau client')
                    ->createOptionAction(fn (Action $action) => $action->visible(auth()->user()->can(PermissionRegistry::CLIENT_CREATE))),
                DatePicker::make('date_validite')->label('Valide jusqu\'au'),
                Textarea::make('conditions_paiement')->label('Conditions de paiement')->columnSpanFull(),
                Textarea::make('notes')->columnSpanFull(),
            ])->columns(2),

            Section::make('Lignes')->schema([
                // Lignes modifiables tant que le devis n'est pas converti en
                // facture, quel que soit son statut (voir
                // Devis::estModifiable()) : cette page n'est accessible que
                // dans ce cas (voir DevisPolicy::update()).
                ...self::lignesDeVente(),
                self::totauxDuDocument(),
            ]),
        ]);
    }

    /**
     * Un devis transformé en facture n'est plus modifiable : sans cette
     * fiche, ses lignes et ses totaux ne seraient plus visibles nulle part.
     * Mêmes colonnes que la facture (voir FactureResource::lignesEntry()).
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Devis')->schema([
                TextEntry::make('numero')->label('Numéro')->placeholder('Brouillon (pas encore envoyé)'),
                TextEntry::make('statut')->badge(),
                TextEntry::make('client.nom')->label('Client'),
                TextEntry::make('date_emission')->label('Envoyé le')->dateTime('d/m/Y')->placeholder('—'),
                TextEntry::make('date_validite')->label('Valide jusqu\'au')->date('d/m/Y')->placeholder('—'),
            ])->columns(3),

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
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->placeholder('Brouillon')->searchable(),
                Tables\Columns\TextColumn::make('client.nom')->label('Client')->searchable(),
                Tables\Columns\TextColumn::make('statut')->badge(),
                Tables\Columns\TextColumn::make('total_ttc')->label('Total TTC')->money('EUR')->sortable(),
                Tables\Columns\TextColumn::make('date_validite')->label('Validité')->date('d/m/Y'),
                Tables\Columns\TextColumn::make('created_at')->label('Créé le')->dateTime('d/m/Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('statut')->options(StatutDevis::class),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                // Un devis reste modifiable (lignes comprises) tant qu'il
                // n'a pas été transformé en facture, voir
                // Devis::estModifiable() et DevisPolicy::update().
                Tables\Actions\EditAction::make()
                    ->visible(fn (Devis $record) => $record->estModifiable()),
                self::telechargerLePdf(Tables\Actions\Action::make('telecharger_pdf')),
                self::envoyerParEmail(Tables\Actions\Action::make('envoyer_par_email')),
                // Remis en main propre, par exemple : le devis reçoit son
                // numéro sans partir par e-mail.
                Tables\Actions\Action::make('envoyer')
                    ->label('Numéroter sans e-mail')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('Le devis reçoit son numéro et passe au statut « Envoyé », sans e-mail : pour un devis remis en main propre, par exemple.')
                    ->visible(fn (Devis $record) => $record->statut === StatutDevis::Brouillon && auth()->user()->can('envoyer', $record))
                    ->action(fn (Devis $record) => static::executerSansCasser(fn () => app(EmettreDevisAction::class)->handle($record))),
                Tables\Actions\Action::make('accepter')
                    ->label('Accepter')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Devis $record) => $record->statut === StatutDevis::Envoye && auth()->user()->can('accepter', $record))
                    ->action(fn (Devis $record) => static::executerSansCasser(fn () => app(AccepterDevisAction::class)->handle($record))),
                Tables\Actions\Action::make('refuser')
                    ->label('Refuser')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Devis $record) => $record->statut === StatutDevis::Envoye && auth()->user()->can('refuser', $record))
                    ->action(fn (Devis $record) => static::executerSansCasser(fn () => app(RefuserDevisAction::class)->handle($record))),
                Tables\Actions\Action::make('convertir')
                    ->label('Convertir en facture')
                    ->icon('heroicon-o-document-currency-euro')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription('Une nouvelle facture brouillon sera créée avec les mêmes lignes que ce devis.')
                    ->visible(fn (Devis $record) => $record->statut === StatutDevis::Accepte
                        && $record->factures()->doesntExist()
                        && auth()->user()->can('convertir', $record))
                    ->action(fn (Devis $record) => static::executerSansCasser(fn () => app(CreerFactureDepuisDevisAction::class)->handle($record))),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    static::suppressionEnMasse(),
                ]),
            ]);
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
