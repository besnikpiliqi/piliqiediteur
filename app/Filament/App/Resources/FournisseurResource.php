<?php

namespace App\Filament\App\Resources;

use App\Domain\Contacts\Models\Fournisseur;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\FournisseurResource\Pages;
use App\Filament\Concerns\ArchiveLaFiche;
use App\Filament\Concerns\SupprimeEnMasseLigneParLigne;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;

class FournisseurResource extends Resource
{
    use ArchiveLaFiche, SupprimeEnMasseLigneParLigne;

    protected static ?string $model = Fournisseur::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Achats';

    protected static ?string $modelLabel = 'fournisseur';

    protected static ?string $pluralModelLabel = 'fournisseurs';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Identité')->schema([
                TextInput::make('nom')->required()->maxLength(150)->columnSpanFull(),
                TextInput::make('siret')
                    ->label('SIRET')
                    ->maxLength(14)
                    ->regex('/^\d{14}$/')
                    // Archivés compris, comme l'index unique de la table : un
                    // fournisseur archivé se retrouve et se restaure depuis la
                    // liste plutôt que d'être recréé.
                    ->unique(
                        table: Fournisseur::class,
                        ignoreRecord: true,
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('magasin_id', app(TenantContext::class)->id()),
                    )
                    ->validationMessages([
                        'regex' => 'Le SIRET compte 14 chiffres, sans espace.',
                        'unique' => 'Ce SIRET est déjà celui d\'un autre fournisseur du magasin, peut-être archivé : affichez les fournisseurs archivés pour le restaurer.',
                    ]),
                TextInput::make('numero_tva')->label('N° TVA intracommunautaire')->maxLength(20),
            ])->columns(2),

            Section::make('Coordonnées')->schema([
                TextInput::make('adresse')->maxLength(255)->columnSpanFull(),
                TextInput::make('complement_adresse')->label('Complément d\'adresse')->maxLength(255)->columnSpanFull(),
                TextInput::make('code_postal')->label('Code postal')->maxLength(10),
                TextInput::make('ville')->maxLength(100),
                TextInput::make('pays')->label('Pays')->maxLength(2)->default('FR'),
                TextInput::make('telephone')->tel()->maxLength(30),
                TextInput::make('email')->email()->maxLength(150),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nom')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('siret')->label('SIRET')->searchable(),
                Tables\Columns\TextColumn::make('email'),
            ])
            ->filters([
                static::filtreDesArchives(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                static::actionRestaurer(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    static::archivageEnMasse(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFournisseurs::route('/'),
            'create' => Pages\CreateFournisseur::route('/create'),
            'edit' => Pages\EditFournisseur::route('/{record}/edit'),
        ];
    }
}
