<?php

namespace App\Filament\App\Resources;

use App\Domain\Tenancy\Models\PosteReconnu;
use App\Domain\Tenancy\Services\PostesReconnus;
use App\Filament\App\Resources\PosteReconnuResource\Pages;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\PrendLeCalendrierDuMagasin;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Les postes reconnus du magasin, dans ses paramètres (voir
 * PostesReconnus et PosteReconnuPolicy) : le propriétaire ou un responsable
 * reconnaît le navigateur qu'il utilise comme un poste du magasin, sous un
 * nom, et retire un poste à tout moment. Tant que le mot de passe d'un
 * compte est encore son identifiant, il n'ouvre ce compte que depuis un
 * poste reconnu de son magasin ; ailleurs, la personne doit avoir choisi le
 * sien. Un poste retiré reste listé, avec qui l'a reconnu et qui l'a
 * retiré. Fermée à la recherche globale.
 */
class PosteReconnuResource extends Resource
{
    use GereLesExceptionsMetier, PrendLeCalendrierDuMagasin;

    protected static ?string $model = PosteReconnu::class;

    protected static ?string $tenantOwnershipRelationshipName = 'magasin';

    protected static bool $isGloballySearchable = false;

    protected static ?string $navigationIcon = 'heroicon-o-computer-desktop';

    protected static ?string $navigationGroup = 'Paramètres';

    protected static ?string $navigationLabel = 'Postes reconnus';

    protected static ?string $modelLabel = 'poste reconnu';

    protected static ?string $pluralModelLabel = 'postes reconnus';

    protected static ?string $slug = 'postes-reconnus';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nom')->label('Poste')->rechercheSansAccents(),
                Tables\Columns\TextColumn::make('ce_navigateur')
                    ->label('')
                    ->badge()
                    ->color('success')
                    ->state(fn (PosteReconnu $record): ?string => app(PostesReconnus::class)->posteDuNavigateur(request())?->is($record) ? 'Ce navigateur' : null),
                Tables\Columns\TextColumn::make('reconnu_par_nom')->label('Reconnu par'),
                Tables\Columns\TextColumn::make('created_at')->label('Le')->dateTime('d/m/Y à H:i'),
                Tables\Columns\TextColumn::make('retire_le')
                    ->label('Retiré')
                    ->state(fn (PosteReconnu $record): ?string => $record->estRetire() ? 'Le '.self::calendrierDuMagasin()->afficher($record->retire_le, 'd/m/Y à H:i').' par '.$record->retire_par_nom : null)
                    ->placeholder('—'),
            ])
            ->actions([
                Tables\Actions\Action::make('retirer')
                    ->label('Retirer')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('Ce poste n\'ouvrira plus aucun compte dont le mot de passe est encore son identifiant.')
                    ->authorize('update')
                    ->action(fn (PosteReconnu $record) => static::executerSansCasser(function () use ($record): void {
                        app(PostesReconnus::class)->retirer(static::auteur(), $record);
                        Notification::make()->title('Le poste est retiré.')->success()->send();
                    })),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPostesReconnus::route('/'),
        ];
    }

    private static function auteur(): User
    {
        $auteur = auth()->user();

        abort_unless($auteur instanceof User, 403);

        return $auteur;
    }
}
