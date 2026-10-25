<?php

namespace App\Filament\App\Resources;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Actions\ModifierUneFicheAction;
use App\Domain\Tenancy\Services\TenantContext;
use App\Domain\Tenancy\Support\FicheLue;
use App\Filament\App\Resources\ServiceResource\Pages\ManageServices;
use App\Filament\Concerns\GereLesExceptionsMetier;
use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

/**
 * Les services que la caisse propose en cases à cocher (voir Caisse) :
 * corriger un nom mal tapé, fixer son prix de référence, retirer de la
 * caisse un service que le magasin ne fait plus, ou dire ce qu'une vente
 * hors de France en fait (frais accessoires, lieu fixé par la loi, voir
 * Service). Jamais supprimé (voir ServicePolicy::delete()) : les factures
 * passées gardent de toute façon leur propre description.
 *
 * Le prix de référence TTC et son taux (R2) ne se fixent que par une
 * personne autorisée à « accorder des remises et fixer le prix d'un
 * service » : pour les autres, ils s'affichent sans se changer, et une
 * valeur envoyée malgré tout ne s'enregistre pas (un champ désactivé ne
 * part pas avec le formulaire) ; l'action du domaine le revérifie sous
 * verrou (voir CreerUneFicheAction et ModifierUneFicheAction).
 */
class ServiceResource extends Resource
{
    use GereLesExceptionsMetier;

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
            ...self::champsDuPrixDeReference()
                ->map(fn (TextInput $champ): TextInput => $champ->disabled(fn (): bool => ! (auth()->user()?->can(PermissionRegistry::VENTE_REMISES_ET_PRIX) ?? false)))
                ->all(),
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
     * Le prix de référence TTC et son taux (voir Service), partagés avec la
     * création d'un service depuis une vente (voir
     * AjouteDesLignesDeVente::actionAjouterUnService()) : un prix entre 0
     * et le plus grand montant enregistrable, un taux entre 0 et 100 %,
     * celui du territoire du magasin par défaut, exigé avec un prix. Sans
     * prix, le taux ne s'enregistre pas.
     *
     * @return Collection<int, TextInput>
     */
    public static function champsDuPrixDeReference(): Collection
    {
        return collect([
            TextInput::make('prix_ttc')
                ->label('Prix de référence TTC')
                ->helperText('Le prix que prend une ligne de ce service. Vide : seul un membre autorisé à fixer les prix vend ce service, au prix qu\'il saisit.')
                ->numeric()
                ->minValue(0)
                ->maxValue(9999999999.99)
                ->prefix('€'),
            TextInput::make('taux_tva')
                ->label('TVA % du prix de référence')
                ->numeric()
                ->minValue(0)
                ->maxValue(100)
                ->default(fn (): string => (string) (float) app(TenantContext::class)->magasin()->tauxTvaParDefaut())
                ->requiredWith('prix_ttc'),
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
                Tables\Columns\TextColumn::make('prix_ttc')->label('Prix de référence TTC')->money('EUR')->placeholder('Non fixé')->sortable(),
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
                self::actionModifier(),
            ]);
    }

    /**
     * « Modifier » : la fenêtre lit la fiche à son ouverture, que la page
     * garde (voir ManageServices::$fichesLues) ; l'enregistrement n'écrit
     * que ce que la personne y a corrigé depuis (voir
     * ModifierUneFicheAction).
     */
    private static function actionModifier(): Tables\Actions\EditAction
    {
        return Tables\Actions\EditAction::make()
            ->mutateRecordDataUsing(function (array $data, Service $record, ManageServices $livewire): array {
                $livewire->fichesLues[$record->getKey()] = FicheLue::de($record)->valeurs();

                return $data;
            })
            ->using(fn (Service $record, array $data, ManageServices $livewire): Service => static::executerOuInterrompre(
                fn (): Service => app(ModifierUneFicheAction::class)->handle(auth()->user(), $record, $livewire->fichesLues[$record->getKey()] ?? [], $data),
            ));
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageServices::route('/'),
        ];
    }
}
