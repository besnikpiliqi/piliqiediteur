<?php

namespace App\Filament\App\Resources;

use App\Domain\Catalogue\Models\Service;
use App\Filament\App\Resources\ServiceResource\Pages;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Les services que la caisse propose en cases à cocher (voir Caisse) :
 * corriger un nom mal tapé, retirer de la caisse un service que le
 * magasin ne fait plus, ou dire ce qu'une vente hors de France en fait
 * (frais accessoires, lieu fixé par la loi, voir Service). Jamais supprimé
 * (voir ServicePolicy::delete()) : les factures passées gardent de toute
 * façon leur propre description.
 */
class ServiceResource extends Resource
{
    protected static ?string $model = Service::class;

    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?string $navigationGroup = 'Catalogue';

    protected static ?string $modelLabel = 'service';

    protected static ?string $pluralModelLabel = 'services';

    public static function form(Form $form): Form
    {
        return $form->schema([
            TextInput::make('nom')
                ->required()
                ->maxLength(Service::LONGUEUR_MAXIMALE_NOM)
                ->rule(fn (): Closure => self::regleNomRempli())
                ->rule(fn (?Service $record): Closure => self::regleNomUnique($record))
                ->columnSpanFull(),
            Toggle::make('actif')
                ->label('Proposé à la caisse')
                ->helperText('Désactivé, il n\'apparaît plus dans les cases à cocher de la caisse. Les factures déjà faites ne changent pas.')
                ->default(true)
                ->columnSpanFull(),
            // Ce qu'une vente hors de France en fait (voir
            // ControleNatureOperation).
            Toggle::make('frais_accessoires')
                ->label('Frais de livraison ou accessoires : port, emballage, assurance')
                ->helperText('Ils suivent les biens qu\'ils accompagnent : un export ou une livraison intracommunautaire peut les facturer sans TVA française.')
                ->default(false)
                ->columnSpanFull(),
            Toggle::make('lieu_specifique')
                ->label('Lieu fixé par la loi : immeuble, transport de personnes, location courte de véhicule, restauration, accès à un événement')
                ->helperText('Jamais facturé sans TVA française à un professionnel établi à l\'étranger (art. 259 A du CGI).')
                ->default(false)
                ->columnSpanFull(),
        ]);
    }

    /**
     * « required » laisse passer un nom fait seulement d'espaces insécables
     * (fréquents sur un clavier de téléphone), qui serait vide une fois
     * nettoyé (voir Service::nettoyerNom()). Partagée avec la caisse.
     */
    public static function regleNomRempli(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (filled($value) && Service::nettoyerNom((string) $value) === '') {
                $fail('Le nom du service ne peut pas être fait seulement d\'espaces.');
            }
        };
    }

    /**
     * Même règle de doublon que la caisse (voir Service::normaliserNom()) :
     * "main d’œuvre" ne peut pas coexister avec "Main d'œuvre".
     */
    private static function regleNomUnique(?Service $record): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($record): void {
            $doublon = Service::query()
                ->where('nom_normalise', Service::normaliserNom((string) $value))
                ->when($record !== null, fn ($query) => $query->whereKeyNot($record->getKey()))
                ->first();

            if ($doublon === null) {
                return;
            }

            $fail($doublon->actif
                ? "Le service « {$doublon->nom} » existe déjà."
                : "Le service « {$doublon->nom} » existe déjà mais n'est plus proposé à la caisse. Réactivez-le plutôt.");
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nom')->rechercheSansAccents()->sortable(),
                Tables\Columns\IconColumn::make('actif')->label('Proposé à la caisse')->boolean(),
                Tables\Columns\IconColumn::make('frais_accessoires')->label('Frais accessoires')->boolean()->toggleable(),
                Tables\Columns\IconColumn::make('lieu_specifique')->label('Lieu fixé par la loi')->boolean()->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label('Créé le')->date('d/m/Y')->sortable(),
            ])
            ->defaultSort('nom')
            ->filters([
                Tables\Filters\TernaryFilter::make('actif')->label('Proposé à la caisse'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageServices::route('/'),
        ];
    }
}
