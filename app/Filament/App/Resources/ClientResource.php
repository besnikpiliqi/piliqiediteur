<?php

namespace App\Filament\App\Resources;

use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Filament\App\Resources\ClientResource\Pages;
use Closure;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class ClientResource extends Resource
{
    protected static ?string $model = Client::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Ventes';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'client';

    protected static ?string $pluralModelLabel = 'clients';

    /**
     * $get('type_client') renvoie l'instance de l'enum (pas sa valeur
     * scalaire) une fois le Select "type_client" live interagi -- état
     * "live" oblige -- d'où la normalisation ici : une comparaison directe
     * à TypeClient::Professionnel->value resterait silencieusement
     * toujours fausse dès qu'un utilisateur change réellement la valeur.
     */
    private static function visibleSiProfessionnel(): Closure
    {
        return function (Get $get): bool {
            $type = $get('type_client');
            $type = $type instanceof TypeClient ? $type->value : $type;

            return $type === TypeClient::Professionnel->value;
        };
    }

    /**
     * Formulaire allégé utilisé dans la modale "+" du Select client_id de
     * DevisResource/FactureResource (createOptionForm) : un vendeur en
     * train de facturer n'a besoin que de l'essentiel pour créer un
     * nouveau client à la volée, pas de toute la fiche complète -- sauf
     * pour un professionnel, qui a besoin de ses informations
     * légales/postales (SIRET, TVA, adresse) dès la création.
     *
     * @return array<Component>
     */
    public static function creationRapideSchema(): array
    {
        $visibleSiProfessionnel = static::visibleSiProfessionnel();

        return [
            TextInput::make('nom')->required()->maxLength(150)->columnSpanFull(),
            Select::make('type_client')
                ->label('Type de client')
                ->options(TypeClient::class)
                ->default(TypeClient::Particulier)
                ->required()
                ->live(),
            TextInput::make('siret')->label('SIRET')->maxLength(14)->visible($visibleSiProfessionnel),
            TextInput::make('numero_tva')->label('N° TVA intracommunautaire')->maxLength(20)->visible($visibleSiProfessionnel),
            TextInput::make('adresse')->maxLength(255)->columnSpanFull()->visible($visibleSiProfessionnel),
            TextInput::make('complement_adresse')->label('Complément d\'adresse')->maxLength(255)->columnSpanFull()->visible($visibleSiProfessionnel),
            TextInput::make('code_postal')->label('Code postal')->maxLength(10)->visible($visibleSiProfessionnel),
            TextInput::make('ville')->maxLength(100)->visible($visibleSiProfessionnel),
            TextInput::make('pays')->label('Pays')->maxLength(2)->default('FR')->visible($visibleSiProfessionnel),
            TextInput::make('telephone')->tel()->maxLength(30),
            TextInput::make('email')->email()->maxLength(150),
        ];
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Identité')->schema([
                TextInput::make('nom')->required()->maxLength(150)->columnSpanFull(),
                Select::make('type_client')
                    ->label('Type de client')
                    ->options(TypeClient::class)
                    ->default(TypeClient::Particulier)
                    ->required()
                    ->live(),
                TextInput::make('siret')
                    ->label('SIRET')
                    ->maxLength(14)
                    ->visible(static::visibleSiProfessionnel()),
                TextInput::make('numero_tva')
                    ->label('N° TVA intracommunautaire')
                    ->maxLength(20)
                    ->visible(static::visibleSiProfessionnel()),
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

            Section::make('Paiement et notes')->schema([
                TextInput::make('delai_paiement_jours')->label('Délai de paiement (jours)')->numeric(),
                TextInput::make('mode_paiement_prefere')->label('Mode de paiement préféré')->maxLength(30),
                Textarea::make('notes')->columnSpanFull(),
                Toggle::make('actif')->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nom')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('type_client')->label('Type')->badge()->sortable(),
                Tables\Columns\TextColumn::make('email')->searchable(),
                Tables\Columns\TextColumn::make('telephone'),
                Tables\Columns\TextColumn::make('ville'),
                // Un avoir n'est jamais remboursé par ce logiciel (pas
                // d'intégration bancaire) : il reste un crédit à appliquer
                // automatiquement sur la prochaine facture de ce client
                // (voir AppliquerAvoirsDisponiblesAction) -- affiché ici en
                // permanence tant qu'il n'est pas intégralement consommé.
                Tables\Columns\TextColumn::make('solde_crediteur')
                    ->label('Solde créditeur')
                    ->state(fn (Client $record) => $record->soldeCrediteur())
                    ->money('EUR')
                    ->color(fn (Client $record) => $record->soldeCrediteur() > 0 ? 'warning' : 'gray')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('actif')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->label('Créé le')->dateTime('d/m/Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type_client')->label('Type')->options(TypeClient::class),
                Tables\Filters\TernaryFilter::make('actif'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/create'),
            'edit' => Pages\EditClient::route('/{record}/edit'),
        ];
    }

    /**
     * Texte d'aide affiché sous le Select client des pages de création de
     * vente (Devis/Facture/Caisse), pour que le vendeur voie le crédit
     * disponible avant même de valider -- même s'il sera de toute façon
     * appliqué automatiquement à l'émission (voir
     * AppliquerAvoirsDisponiblesAction).
     */
    public static function soldeCrediteurHelperText(mixed $clientId): ?string
    {
        if (blank($clientId)) {
            return null;
        }

        $solde = Client::find($clientId)?->soldeCrediteur();

        if (blank($solde) || $solde <= 0) {
            return null;
        }

        return 'Crédit disponible pour ce client : '.number_format($solde, 2, ',', ' ').' € (sera appliqué automatiquement).';
    }
}
