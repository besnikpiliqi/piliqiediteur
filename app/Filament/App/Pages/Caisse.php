<?php

namespace App\Filament\App\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Filament\App\Resources\ClientResource;
use App\Filament\App\Resources\DevisResource;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use LogicException;

/**
 * Caisse rapide pour une vente comptoir : un vendeur encaisse directement
 * ici, sans passer par le formulaire complet d'une facture B2B (voir
 * FactureResource). Délègue à EnregistrerVenteCaisseAction la création,
 * l'émission et l'encaissement intégral en une seule opération -- une
 * vente comptoir n'est jamais "à régler plus tard".
 *
 * Réutilise DevisResource::ligneSchema() (même recherche produit par
 * nom/référence/code-barres, même création rapide de produit) : aucune
 * raison de dupliquer cette logique pour un troisième formulaire de
 * lignes.
 *
 * Accessible à quiconque peut créer une facture (voir canAccess()) : la
 * caisse n'introduit aucune permission dédiée, elle n'est qu'une façon
 * plus rapide de faire ce qu'un vendeur peut déjà faire via le
 * formulaire complet.
 */
class Caisse extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Ventes';

    protected static ?string $navigationLabel = 'Caisse';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Caisse';

    protected static string $view = 'filament.app.pages.caisse';

    /**
     * @var array<string, mixed>
     */
    public array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can(PermissionRegistry::FACTURE_CREATE) ?? false;
    }

    public function mount(): void
    {
        $this->remplirFormulaireVide();
    }

    private function remplirFormulaireVide(): void
    {
        $this->form->fill([
            'client_id' => Client::query()->where('nom', Client::NOM_COMPTOIR)->value('id'),
            'lignes' => [],
            'mode_paiement' => ModePaiement::Especes->value,
            'montant_recu' => null,
            'reference' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Client')->schema([
                Select::make('client_id')
                    ->label('Client')
                    ->live()
                    ->helperText(fn (Get $get) => ClientResource::soldeCrediteurHelperText($get('client_id'))
                        ?? 'Par défaut : le client comptoir générique, pour une vente anonyme.')
                    ->searchable()
                    ->getSearchResultsUsing(fn (string $search) => Client::query()
                        ->where('nom', 'like', "%{$search}%")
                        ->limit(50)
                        ->pluck('nom', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Client::find($value)?->nom)
                    // Form explicitement modelé sur Client : ce Select
                    // n'utilise pas relationship(), voir le commentaire
                    // équivalent dans DevisResource::ligneSchema().
                    ->createOptionForm(fn (Form $form): Form => $form
                        ->model(Client::class)
                        ->schema(ClientResource::creationRapideSchema()))
                    ->createOptionModalHeading('Nouveau client')
                    ->createOptionAction(fn (Action $action) => $action->visible(auth()->user()->can(PermissionRegistry::CLIENT_CREATE)))
                    ->createOptionUsing(fn (array $data) => Client::create($data)->getKey())
                    ->required(),
            ]),

            Section::make('Produits')->schema([
                Repeater::make('lignes')
                    ->label('')
                    ->schema(DevisResource::ligneSchema())
                    ->columns(9)
                    ->defaultItems(1)
                    ->addActionLabel('Ajouter un produit')
                    ->required(),
            ]),

            Section::make('Paiement')->schema([
                Placeholder::make('total_a_payer')
                    ->label('Total à payer')
                    ->content(fn (Get $get) => $this->formaterMontant($this->totalTtc($get('lignes') ?? []))),
                Select::make('mode_paiement')
                    ->label('Mode de paiement')
                    ->options(ModePaiement::class)
                    ->default(ModePaiement::Especes)
                    ->required()
                    ->live(),
                TextInput::make('montant_recu')
                    ->label('Montant reçu')
                    ->numeric()
                    ->prefix('€')
                    // live() et non live(onBlur: true) : la monnaie à rendre
                    // doit se mettre à jour pendant la saisie, pas seulement
                    // quand le vendeur clique ailleurs.
                    ->live()
                    ->visible(fn (Get $get) => $this->modePaiementSelectionne($get) === ModePaiement::Especes),
                Placeholder::make('monnaie_a_rendre')
                    ->label('Monnaie à rendre')
                    ->visible(fn (Get $get) => $this->modePaiementSelectionne($get) === ModePaiement::Especes)
                    ->content(function (Get $get) {
                        $rendu = (float) ($get('montant_recu') ?? 0) - $this->totalTtc($get('lignes') ?? []);

                        return $this->formaterMontant(max(0, $rendu));
                    }),
                TextInput::make('reference')
                    ->label('Référence (optionnel)')
                    ->helperText('N° de transaction CB, de chèque...')
                    ->maxLength(100),
            ])->columns(2),
        ])->statePath('data');
    }

    public function encaisser(): void
    {
        $data = $this->form->getState();

        $client = Client::query()->findOrFail($data['client_id']);
        $modePaiement = $this->modePaiementDepuisValeur($data['mode_paiement']);

        try {
            $facture = app(EnregistrerVenteCaisseAction::class)->handle(
                $client,
                $data['lignes'],
                $modePaiement,
                $data['reference'] ?? null,
            );
        } catch (LogicException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title("Vente encaissée : facture {$facture->numero}")
            ->body($this->resumeEncaissement($facture))
            ->success()
            ->send();

        $this->remplirFormulaireVide();
    }

    /**
     * Le crédit disponible du client (voir AppliquerAvoirsDisponiblesAction)
     * peut déjà avoir couvert tout ou partie de la vente au moment de
     * l'émission, avant même que la caisse n'encaisse quoi que ce soit en
     * espèces/CB -- le message doit refléter ce qui a RÉELLEMENT été
     * encaissé, pas le total brut de la facture, sous peine de fausser la
     * caisse du vendeur en fin de journée.
     */
    private function resumeEncaissement(Facture $facture): string
    {
        $credite = $facture->montantCrediteParAvoir();

        if ($credite <= 0) {
            return $this->formaterMontant((float) $facture->total_ttc).' encaissé.';
        }

        $paye = $facture->montantPaye();

        if ($paye <= 0) {
            return 'Réglée intégralement par le crédit disponible du client ('.$this->formaterMontant($credite).').';
        }

        return $this->formaterMontant($paye).' encaissé ('.$this->formaterMontant($credite).' crédité par avoir).';
    }

    /**
     * Passe par CalculateurLigne (même service que celui qui calcule
     * réellement la facture persistée via EnregistrerVenteCaisseAction) :
     * sans ça, cet aperçu -- et donc la monnaie à rendre affichée au
     * vendeur -- pouvait afficher un total différent de quelques centimes
     * par rapport à ce qui est effectivement encaissé, selon comment
     * l'arrondi tombait sur chaque ligne avant d'être sommée.
     *
     * @param  list<array<string, mixed>>  $lignes
     */
    private function totalTtc(array $lignes): float
    {
        $calculateur = app(CalculateurLigne::class);

        $montants = collect($lignes)->map(fn (array $ligne) => $calculateur->calculer(
            (float) ($ligne['prix_unitaire_ht'] ?? 0),
            (float) ($ligne['quantite'] ?? 0),
            (float) ($ligne['taux_tva'] ?? 0),
            (float) ($ligne['remise_pourcentage'] ?? 0),
        ));

        return $calculateur->totaliser($montants)['total_ttc'];
    }

    private function formaterMontant(float $montant): string
    {
        return number_format($montant, 2, ',', ' ').' €';
    }

    /**
     * $get('mode_paiement') renvoie l'instance de l'enum (pas sa valeur
     * scalaire) une fois le Select live interagi -- voir
     * ClientResource::visibleSiProfessionnel() pour le même piège déjà
     * rencontré et corrigé ailleurs dans l'application.
     */
    private function modePaiementSelectionne(Get $get): ?ModePaiement
    {
        return $this->modePaiementDepuisValeur($get('mode_paiement'));
    }

    private function modePaiementDepuisValeur(mixed $valeur): ?ModePaiement
    {
        if ($valeur instanceof ModePaiement) {
            return $valeur;
        }

        return $valeur ? ModePaiement::tryFrom((string) $valeur) : null;
    }
}
