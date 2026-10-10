<?php

namespace App\Filament\App\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Services\BaremePrixDeVente;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Models\Magasin;
use App\Filament\App\Resources\ProduitResource;
use Filament\Facades\Filament;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use InvalidArgumentException;

/**
 * Le barème avec lequel le magasin fixe ses prix de vente (voir
 * BaremePrixDeVente) : un bénéfice selon le prix d'achat de l'article,
 * appliqué aux produits de ses factures d'achat.
 */
class ParametresPrixDeVente extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calculator';

    protected static ?string $navigationGroup = 'Paramètres';

    protected static ?string $navigationLabel = 'Prix de vente';

    protected static ?string $title = 'Prix de vente';

    protected static string $view = 'filament.app.pages.formulaire-de-parametres';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can(PermissionRegistry::PRIX_VENTE_REGLER) ?? false;
    }

    public function mount(): void
    {
        /** @var Magasin $magasin */
        $magasin = Filament::getTenant();

        $this->form->fill([
            'actif' => $magasin->prix_vente_automatique,
            ...$magasin->bareme_prix_vente->versTableau(),
            'essai_taux_tva' => $magasin->tauxTvaParDefaut(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Toggle::make('actif')
                ->label('Calculer automatiquement le prix de vente')
                ->helperText('Dans une facture d\'achat, le prix de vente TTC d\'un produit se remplit d\'après ce barème dès que vous saisissez son prix d\'achat. Vous pouvez toujours le corriger. Désactivé, votre barème est gardé pour le jour où vous le réactivez.')
                ->live()
                // Désactiver remet le barème enregistré : une saisie laissée
                // en cours ne se retrouve ni grisée à l'écran comme si elle
                // valait, avec ses erreurs, ni bloquante à l'enregistrement.
                ->afterStateUpdated(function (bool $state, Set $set): void {
                    if ($state) {
                        return;
                    }

                    /** @var Magasin $magasin */
                    $magasin = Filament::getTenant();

                    foreach ($magasin->fresh()->bareme_prix_vente->versTableau() as $champ => $valeur) {
                        $set($champ, $valeur);
                    }

                    $this->resetErrorBag();
                }),
            // Visible même désactivé, pour que le magasin voie d'emblée de
            // quoi il s'agit ; il ne se modifie qu'une fois activé.
            Section::make('Bénéfice selon le prix d\'achat')
                ->description(fn (Get $get): string => 'Prix de vente TTC = (prix d\'achat HT + bénéfice) + TVA. Le prix d\'achat est celui de la ligne, remise du fournisseur déduite. '
                    .($get('actif')
                        ? 'Le logiciel vous propose ces paliers au départ : modifiez-les, supprimez-les ou ajoutez-en autant que vous voulez.'
                        : 'Voici un exemple de barème : activez le calcul pour l\'utiliser et le régler comme vous voulez.'))
                ->schema([
                    Repeater::make('paliers')
                        ->label('')
                        ->schema([
                            TextInput::make('jusqu_a')
                                ->label('Prix d\'achat HT inférieur à')
                                ->numeric()
                                ->minValue(0.01)
                                ->suffix('€')
                                ->required()
                                ->distinct(),
                            TextInput::make('benefice')
                                ->label('Bénéfice ajouté')
                                ->numeric()
                                ->minValue(0)
                                ->suffix('€ HT')
                                ->required(),
                        ])
                        ->columns(2)
                        ->reorderable(false)
                        ->addActionLabel('Ajouter un palier')
                        ->disabled(fn (Get $get): bool => ! $get('actif')),
                    TextInput::make('benefice_au_dela')
                        ->label('Bénéfice au-delà du dernier palier')
                        ->helperText('Laissez vide pour saisir à la main le prix de vente des articles plus chers.')
                        ->numeric()
                        ->minValue(0)
                        ->suffix('€ HT')
                        ->required(fn (Get $get): bool => $get('actif') && blank($get('paliers')))
                        ->validationMessages(['required' => 'Sans palier, indiquez au moins le bénéfice à appliquer.'])
                        ->disabled(fn (Get $get): bool => ! $get('actif')),
                    Toggle::make('arrondi_x9')
                        ->label('Arrondir le prix au X9 le plus proche')
                        ->helperText('77,60 € devient 79 €, 74,00 € devient 69 €, 134,40 € devient 129 €. Un prix sous 5 € reste tel quel.')
                        ->disabled(fn (Get $get): bool => ! $get('actif')),
                ]),
            Section::make('Essayer le barème')
                ->schema([
                    TextInput::make('essai_prix_achat_ht')
                        ->label('Prix d\'achat HT')
                        ->numeric()
                        ->suffix('€')
                        ->live(debounce: 400)
                        ->dehydrated(false),
                    TextInput::make('essai_taux_tva')
                        ->label('TVA')
                        ->numeric()
                        ->suffix('%')
                        ->live(debounce: 400)
                        ->dehydrated(false),
                    Placeholder::make('essai_prix_vente')
                        ->label('Prix de vente TTC')
                        ->content(fn (Get $get): string => self::essayer($get)),
                ])->columns(3),
        ])->statePath('data');
    }

    /**
     * Le barème tel qu'il est en train d'être saisi, appliqué au prix
     * d'achat d'essai -- comme le fera une facture d'achat, qui ne propose
     * rien tant que la TVA est vide.
     */
    private static function essayer(Get $get): string
    {
        $tauxTva = ProduitResource::tauxDeTvaSaisi($get('essai_taux_tva'));

        if (blank($get('essai_prix_achat_ht')) || $tauxTva === null) {
            return '—';
        }

        try {
            $calculateur = app(CalculateurLigne::class);
            $prixAchatHt = (float) $get('essai_prix_achat_ht');
            $prix = self::baremeSaisi($get)->prixDeVente($prixAchatHt, $tauxTva);

            if ($prix === null) {
                return 'Aucun bénéfice prévu à ce prix d\'achat : le prix de vente se saisit à la main.';
            }

            $beneficeReel = $calculateur->beneficeUnitaire($calculateur->prixUnitaireHtDepuisTtc($prix['prix_vente_ttc'], $tauxTva), $prixAchatHt);
        } catch (InvalidArgumentException) {
            return '—';
        }

        return self::euros($prix['prix_vente_ttc']).' TTC'
            .($prix['prix_vente_ttc'] !== $prix['prix_cible_ttc'] ? ' (prix cible '.self::euros($prix['prix_cible_ttc']).')' : '')
            .' · bénéfice réel '.self::euros($beneficeReel).' HT (visé '.self::euros($prix['benefice']).')';
    }

    private static function baremeSaisi(Get $get): BaremePrixDeVente
    {
        return BaremePrixDeVente::depuisTableau([
            'paliers' => array_values(array_filter(
                $get('paliers') ?? [],
                fn (array $palier): bool => filled($palier['jusqu_a'] ?? null) && filled($palier['benefice'] ?? null),
            )),
            'benefice_au_dela' => $get('benefice_au_dela'),
            'arrondi_x9' => (bool) $get('arrondi_x9'),
        ]);
    }

    private static function euros(float $montant): string
    {
        return number_format($montant, 2, ',', ' ').' €';
    }

    public function save(): void
    {
        $data = $this->form->getState();

        /** @var Magasin $magasin */
        $magasin = Filament::getTenant();

        // Désactivé, le barème ne se modifie pas : celui enregistré reste
        // tel quel.
        if (! $data['actif']) {
            $magasin->update(['prix_vente_automatique' => false]);

            Notification::make()->title('Prix de vente automatique désactivé')->success()->send();

            return;
        }

        try {
            $bareme = BaremePrixDeVente::depuisTableau([
                'paliers' => array_values($data['paliers'] ?? []),
                'benefice_au_dela' => $data['benefice_au_dela'],
                'arrondi_x9' => $data['arrondi_x9'],
            ]);
        } catch (InvalidArgumentException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        $magasin->update(['prix_vente_automatique' => true, 'bareme_prix_vente' => $bareme]);

        Notification::make()->title('Barème de prix de vente enregistré')->success()->send();
    }
}
