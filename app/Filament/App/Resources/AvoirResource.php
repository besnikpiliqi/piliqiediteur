<?php

namespace App\Filament\App\Resources;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Services\AvoirPdfGenerator;
use App\Filament\App\Resources\AvoirResource\Pages;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lecture seule : un avoir se crée uniquement depuis l'action rapide
 * "Avoir" de FactureResource (total ou partiel selon les lignes/quantités
 * sélectionnées, voir CreerAvoirAction) et ne se modifie ni ne se supprime
 * jamais une fois émis (voir AvoirPolicy). Aucune page create/edit ici.
 */
class AvoirResource extends Resource
{
    protected static ?string $model = Avoir::class;

    protected static ?string $navigationIcon = 'heroicon-o-receipt-refund';

    protected static ?string $navigationGroup = 'Ventes';

    protected static ?int $navigationSort = 4;

    protected static ?string $modelLabel = 'avoir';

    protected static ?string $pluralModelLabel = 'avoirs';

    /**
     * Même confidentialité par vendeur que FactureResource : voir
     * AvoirPolicy::view.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['facture.client']);

        if (! auth()->user()->can(PermissionRegistry::FACTURE_VIEW_TOUTES)) {
            $query->whereHas('facture', fn (Builder $q) => $q->where('cree_par', auth()->id()));
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->searchable(),
                Tables\Columns\TextColumn::make('facture.numero')->label('Facture créditée')->searchable(),
                Tables\Columns\TextColumn::make('facture.client.nom')->label('Client')->searchable(),
                Tables\Columns\TextColumn::make('total_ttc')->label('Total TTC')->money('EUR')->sortable(),
                Tables\Columns\TextColumn::make('motif')->limit(40)->toggleable(),
                Tables\Columns\TextColumn::make('date_emission')->label('Émis le')->dateTime('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('creePar.name')->label('Émis par')->toggleable(),
            ])
            ->actions([
                Tables\Actions\Action::make('telecharger_pdf')
                    ->label('PDF')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(function (Avoir $record) {
                        $generateur = app(AvoirPdfGenerator::class);

                        return response()->streamDownload(
                            fn () => print ($generateur->generer($record)->output()),
                            $generateur->nomFichier($record),
                        );
                    }),
            ])
            // Aucune action de suppression, ni unitaire ni groupée : un
            // avoir émis ne se supprime jamais (voir AvoirPolicy::delete()).
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAvoirs::route('/'),
        ];
    }
}
