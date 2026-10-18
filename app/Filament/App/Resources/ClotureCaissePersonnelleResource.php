<?php

namespace App\Filament\App\Resources;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Facturation\Enums\ModeFermetureCaisse;
use App\Domain\Facturation\Models\ClotureCaissePersonnelle;
use App\Filament\App\Resources\ClotureCaissePersonnelleResource\Pages;
use App\Models\User;
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
 *
 * Le menu et la page s'appellent « Ma caisse » pour qui tient une caisse
 * dans le magasin sans voir les relevés de toute l'équipe, « Les caisses »
 * pour tous les autres (le propriétaire, un responsable, un administrateur
 * qui ne fait que fermer la caisse d'un vendeur, un expert-comptable...),
 * qui y voient aussi les caisses encore ouvertes (voir neVoitQueSaCaisse()
 * et CaissesOuvertesDeLEquipeApercu).
 */
class ClotureCaissePersonnelleResource extends Resource
{
    protected static ?string $model = ClotureCaissePersonnelle::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationGroup = 'Ventes';

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = 'relevé de caisse';

    protected static ?string $pluralModelLabel = 'relevés de caisse';

    public static function getNavigationLabel(): string
    {
        return static::libelle();
    }

    /**
     * « Ma caisse » pour qui ne voit que sa caisse, « Les caisses » pour
     * tous les autres.
     */
    public static function libelle(): string
    {
        return static::neVoitQueSaCaisse() ? 'Ma caisse' : 'Les caisses';
    }

    /**
     * La personne connectée tient-elle une caisse dans le magasin actif, ou
     * y a-t-elle encore la sienne ouverte (voir
     * ClotureCaissePersonnellePolicy::fermer()), sans voir les relevés de
     * toute l'équipe ? Elle n'y voit alors que ses relevés, jamais les
     * caisses des autres (voir CaissesOuvertesDeLEquipeApercu), même si
     * elle peut clôturer la journée.
     */
    public static function neVoitQueSaCaisse(): bool
    {
        $compte = auth()->user();

        return $compte instanceof User
            && ! $compte->can(PermissionRegistry::CLOTURE_CAISSE_VIEW_TOUTES)
            && $compte->can('fermer', ClotureCaissePersonnelle::class);
    }

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
