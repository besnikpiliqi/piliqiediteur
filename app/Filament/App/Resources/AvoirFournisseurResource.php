<?php

namespace App\Filament\App\Resources;

use App\Domain\Achats\Enums\SourceAvoirFournisseur;
use App\Domain\Achats\Models\AvoirFournisseur;
use App\Filament\App\Resources\AvoirFournisseurResource\Pages;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lecture seule : un avoir fournisseur se crée uniquement depuis l'action
 * rapide "Avoir fournisseur" de FactureAchatResource et ne se modifie ni
 * ne se supprime jamais une fois émis (voir AvoirFournisseurPolicy).
 * Aucune page create/edit ici, seulement sa fiche.
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

    /**
     * Mêmes colonnes que la facture d'achat (voir
     * FactureResource::lignesEntry()) : pour chaque article retourné, PU
     * HT, taux, puis HT, TVA et TTC de la ligne, et le récapitulatif par
     * taux.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            InfolistSection::make('Avoir fournisseur')->schema([
                TextEntry::make('numero')->label('Numéro')->placeholder('Brouillon (pas encore émis)'),
                TextEntry::make('statut')->badge(),
                TextEntry::make('source')->badge(),
                TextEntry::make('factureAchat.numero')->label('Facture d\'achat créditée'),
                TextEntry::make('factureAchat.fournisseur.nom')->label('Fournisseur'),
                TextEntry::make('date_emission')->label('Émis le')->dateTime('d/m/Y')->placeholder('—'),
                TextEntry::make('motif')->label('Motif')->placeholder('—')->columnSpanFull(),
            ])->columns(3),

            InfolistSection::make('Produits retournés')->schema([
                FactureResource::lignesEntry($infolist->getRecord()),
            ]),

            InfolistSection::make('Totaux')->schema([
                TextEntry::make('total_ht')->label('Total HT')->money('EUR'),
                TextEntry::make('total_tva')->label('Total TVA')->money('EUR'),
                TextEntry::make('total_ttc')->label('Total TTC')->money('EUR'),
                FactureResource::ventilationTvaEntry(),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('numero')->label('Numéro')->rechercheSansAccents(),
                Tables\Columns\TextColumn::make('factureAchat.numero')->label('Facture d\'achat créditée')->rechercheSansAccents(),
                Tables\Columns\TextColumn::make('factureAchat.fournisseur.nom')->label('Fournisseur')->rechercheSansAccents(),
                Tables\Columns\TextColumn::make('total_ttc')->label('Total TTC')->money('EUR')->sortable(),
                Tables\Columns\TextColumn::make('motif')->limit(40)->toggleable(),
                Tables\Columns\TextColumn::make('source')->badge()->toggleable(),
                Tables\Columns\TextColumn::make('date_emission')->label('Émis le')->dateTime('d/m/Y')->sortable(),
                Tables\Columns\TextColumn::make('cree_par_nom')->label('Émis par')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('source')->options(SourceAvoirFournisseur::class),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            // Aucune action de suppression, ni unitaire ni groupée : un
            // avoir fournisseur émis ne se supprime jamais (voir
            // AvoirFournisseurPolicy::delete()).
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAvoirsFournisseur::route('/'),
            'view' => Pages\ViewAvoirFournisseur::route('/{record}'),
        ];
    }
}
