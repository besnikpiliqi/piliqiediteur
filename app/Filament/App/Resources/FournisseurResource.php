<?php

namespace App\Filament\App\Resources;

use App\Domain\Contacts\Enums\SourceFournisseur;
use App\Domain\Contacts\Models\Fournisseur;
use App\Filament\App\Resources\FournisseurResource\Pages;
use App\Filament\Concerns\SupprimeEnMasseLigneParLigne;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FournisseurResource extends Resource
{
    use SupprimeEnMasseLigneParLigne;

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
                TextInput::make('siret')->label('SIRET')->maxLength(14),
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

            Toggle::make('actif')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nom')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('siret')->label('SIRET')->searchable(),
                Tables\Columns\TextColumn::make('source')->badge(),
                Tables\Columns\TextColumn::make('email'),
                Tables\Columns\IconColumn::make('actif')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('source')->options(SourceFournisseur::class),
                Tables\Filters\TernaryFilter::make('actif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            'index' => Pages\ListFournisseurs::route('/'),
            'create' => Pages\CreateFournisseur::route('/create'),
            'edit' => Pages\EditFournisseur::route('/{record}/edit'),
        ];
    }
}
