<?php

namespace App\Filament\Societe\Resources;

use App\Domain\Tenancy\Models\Magasin;
use App\Filament\Societe\Resources\MagasinResource\Pages;
use App\Filament\Societe\RessourceDuPanneauSociete;
use App\Models\User;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Les magasins réels de la société, dans son espace d'administration (voir
 * MagasinDeLaSocietePolicy) : la liste de ceux de la portée de la personne
 * connectée (tous pour le propriétaire et un administrateur de tous les
 * magasins, ceux de sa sélection pour un autre administrateur), chacun
 * avec son code, son SIRET, son établissement (vérifié ou à vérifier) et
 * son état ; et la création d'un magasin (voir CreerMagasin), pour qui peut
 * en créer. Le magasin de démonstration se trouve sur le tableau de bord.
 * Un magasin ne se supprime jamais.
 */
class MagasinResource extends RessourceDuPanneauSociete
{
    protected static ?string $model = Magasin::class;

    protected static ?string $tenantOwnershipRelationshipName = 'societe';

    protected static bool $isGloballySearchable = false;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Magasins';

    protected static ?string $modelLabel = 'magasin';

    protected static ?string $pluralModelLabel = 'magasins';

    protected static ?string $slug = 'magasins';

    protected static ?int $navigationSort = 1;

    /**
     * Les magasins réels de la portée de la personne connectée, dans sa
     * société (voir User::magasinsDeSaPortee()).
     *
     * @return Builder<Magasin>
     */
    public static function getEloquentQuery(): Builder
    {
        $auteur = auth()->user();

        return $auteur instanceof User
            ? $auteur->magasinsDeSaPortee()->where('est_demonstration', false)
            : Magasin::query()->whereRaw('1 = 0');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nom')->label('Nom')->rechercheSansAccents()->sortable(),
                Tables\Columns\TextColumn::make('code')->label('Code'),
                Tables\Columns\TextColumn::make('siret')->label('SIRET'),
                Tables\Columns\TextColumn::make('ville')->label('Ville')->placeholder('—'),
                Tables\Columns\TextColumn::make('etablissement')
                    ->label('Établissement')
                    ->state(fn (Magasin $record): string => $record->etablissement_verifie_le === null ? 'à vérifier' : 'vérifié')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'vérifié' ? 'success' : 'warning'),
                Tables\Columns\TextColumn::make('etat')
                    ->label('État')
                    ->state(fn (Magasin $record): string => $record->motifDeLectureSeule() ?? 'ouvert')
                    ->wrap(),
            ])
            ->actions([])
            ->bulkActions([])
            ->emptyStateHeading('Aucun magasin pour le moment')
            ->emptyStateDescription('Créez votre premier magasin en choisissant son établissement parmi ceux de votre SIREN au répertoire Sirene.')
            ->defaultSort('nom');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMagasins::route('/'),
            'create' => Pages\CreerMagasin::route('/creer'),
        ];
    }
}
