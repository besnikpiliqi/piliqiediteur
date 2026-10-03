<?php

namespace App\Filament\App\Resources;

use App\Domain\Achats\Models\AvoirFournisseur;
use App\Filament\App\Resources\AvoirFournisseurResource\Pages;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lecture seule : un avoir fournisseur se crée uniquement depuis l'action
 * rapide "Avoir fournisseur" de FactureAchatResource et ne se modifie ni
 * ne se supprime jamais une fois émis (voir AvoirFournisseurPolicy).
 * Aucune page create/edit ici.
 */
class AvoirFournisseurResource extends Resource
{
    protected static ?string $model = AvoirFournisseur::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?string $navigationGroup = 'Achats';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'avoir fournisseur';

    protected static ?string $pluralModelLabel = 'avoirs fournisseur';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['factureAchat.fournisseur']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->searchable(),
                Tables\Columns\TextColumn::make('factureAchat.numero')->label('Facture d\'achat créditée')->searchable(),
                Tables\Columns\TextColumn::make('factureAchat.fournisseur.nom')->label('Fournisseur')->searchable(),
                Tables\Columns\TextColumn::make('total_ttc')->label('Total TTC')->money('EUR')->sortable(),
                Tables\Columns\TextColumn::make('motif')->limit(40)->toggleable(),
                Tables\Columns\TextColumn::make('date_emission')->label('Émis le')->dateTime('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('creePar.name')->label('Émis par')->toggleable(),
            ])
            ->actions([])
            // Aucune action de suppression, ni unitaire ni groupée : un
            // avoir fournisseur émis ne se supprime jamais (voir
            // AvoirFournisseurPolicy::delete()).
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAvoirsFournisseur::route('/'),
        ];
    }
}
