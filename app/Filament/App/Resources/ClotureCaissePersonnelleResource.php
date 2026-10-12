<?php

namespace App\Filament\App\Resources;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Enums\ModeFermetureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource\Pages;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lecture seule : un relevé se crée uniquement par une fermeture (« Fermer
 * ma caisse », « Fermer la caisse d'un vendeur », voir
 * ListClotureCaissePersonnelles, ou d'office avec la journée, voir
 * FermerLesCaissesPersonnellesAction), jamais via un formulaire
 * create/edit classique. Voir le docblock de ClotureCaissePersonnelle pour
 * ce qui la distingue de ClotureCaisse (la clôture officielle de la
 * journée du magasin).
 */
class ClotureCaissePersonnelleResource extends Resource
{
    protected static ?string $model = ClotureCaissePersonnelle::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Ventes';

    protected static ?string $navigationLabel = 'Ma caisse';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'relevé de caisse';

    protected static ?string $pluralModelLabel = 'relevés de caisse';

    /**
     * Même confidentialité que ClotureCaisse : sans cloture_caisse.view_toutes,
     * un vendeur ne voit que ses propres relevés.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if (! auth()->user()->can(PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES)) {
            $query->where('vendeur_id', auth()->id());
        }

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('cloturee_a', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('vendeur_nom')->label('Vendeur'),
                Tables\Columns\TextColumn::make('periode_debut')->label('Depuis')->dateTime('d/m/Y H:i'),
                Tables\Columns\TextColumn::make('jusqu_au')->label('Jusqu\'à')->dateTime('d/m/Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('mode_fermeture')
                    ->label('Fermeture')
                    ->state(fn (ClotureCaissePersonnelle $record): string => $record->libelleDeFermeture())
                    ->badge()
                    ->color(fn (ClotureCaissePersonnelle $record): string => $record->mode_fermeture === ModeFermetureCaisse::Automatique ? 'warning' : 'gray'),
                Tables\Columns\TextColumn::make('nombre_ventes')->label('Ventes'),
                // Les quatre rubriques du relevé (B18, voir
                // ClotureCaissePersonnelle), chacune à part.
                Tables\Columns\TextColumn::make('total_ttc')->label('TTC vendu')->money('EUR'),
                Tables\Columns\TextColumn::make('total_encaisse')
                    ->label('Encaissé')
                    ->state(fn (ClotureCaissePersonnelle $record): float => $record->totalEncaisse())
                    ->money('EUR'),
                Tables\Columns\TextColumn::make('encaissements')
                    ->label('Par mode de paiement')
                    ->state(fn (ClotureCaissePersonnelle $record): array => $record->encaissementsEnClair())
                    ->listWithLineBreaks()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('credit_avoir')->label('Crédit d\'avoir')->money('EUR'),
                Tables\Columns\TextColumn::make('reste_du')->label('Reste dû')->money('EUR'),
            ])
            ->bulkActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClotureCaissePersonnelles::route('/'),
        ];
    }
}
