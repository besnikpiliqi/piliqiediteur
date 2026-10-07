<?php

namespace App\Filament\App\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Catalogue\Actions\TrouverOuCreerServiceAction;
use App\Domain\Catalogue\Models\Service;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\PrixReference;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Filament\App\Resources\ClientResource;
use App\Filament\App\Resources\DevisResource;
use App\Filament\App\Resources\ServiceResource;
use Closure;
use Filament\Forms\Components\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\CheckboxList;
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
use Filament\Support\Enums\ActionSize;
use Filament\Support\Enums\MaxWidth;
use InvalidArgumentException;
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
     * Plus grand montant que les colonnes decimal(12,2) peuvent enregistrer.
     */
    public const MONTANT_MAXIMUM = 9999999999.99;

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

            Section::make('Produits et services')->schema([
                Repeater::make('lignes')
                    ->label('')
                    ->schema(DevisResource::ligneSchema())
                    ->columns(9)
                    ->defaultItems(1)
                    ->itemLabel(fn (array $state): string => filled($state['service_id'] ?? null) ? 'Service' : 'Produit')
                    // Remplacé par les deux boutons côte à côte ci-dessous.
                    ->addable(false)
                    ->required(),
                Actions::make([
                    $this->actionAjouterUnProduit(),
                    $this->actionAjouterUnService(),
                ])->alignCenter(),
                DevisResource::totauxDuDocument(PrixReference::Ttc),
            ]),

            Section::make('Paiement')->schema([
                Placeholder::make('total_a_payer')
                    ->label('Total à payer')
                    ->content(fn (Get $get) => $this->formaterMontant($this->resteAPayer($get)))
                    ->helperText(fn (Get $get) => $this->detailCreditApplique($get)),
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
                    // Facultatif (sert à calculer la monnaie), mais s'il est
                    // saisi il doit couvrir ce qui reste à payer : sinon la
                    // vente serait enregistrée payée avec un tiroir-caisse
                    // en manque.
                    ->minValue(fn (Get $get): float => $this->resteAPayer($get))
                    ->maxValue(self::MONTANT_MAXIMUM)
                    ->validationMessages(['min' => 'Le montant reçu ne couvre pas le reste à payer.'])
                    // Et non live(onBlur: true) : la monnaie à rendre doit se
                    // mettre à jour pendant la saisie, pas seulement quand le
                    // vendeur clique ailleurs.
                    ->saisieEnDirect()
                    ->visible(fn (Get $get) => $this->modePaiementSelectionne($get) === ModePaiement::Especes),
                Placeholder::make('monnaie_a_rendre')
                    ->label('Monnaie à rendre')
                    ->visible(fn (Get $get) => $this->modePaiementSelectionne($get) === ModePaiement::Especes)
                    ->content(function (Get $get) {
                        try {
                            $rendu = app(CalculateurLigne::class)->soustraire($get('montant_recu'), $this->resteAPayer($get));
                        } catch (InvalidArgumentException) {
                            return '—';
                        }

                        return $this->formaterMontant(max(0, $rendu));
                    }),
                TextInput::make('reference')
                    ->label('Référence (optionnel)')
                    ->helperText('N° de transaction CB, de chèque...')
                    ->maxLength(100),
            ])->columns(2),
        ])->statePath('data');
    }

    private function actionAjouterUnProduit(): Action
    {
        return Action::make('ajouterUnProduit')
            ->label('Ajouter un produit')
            ->color('gray')
            ->size(ActionSize::Small)
            ->action(fn () => $this->ajouterUneLigne());
    }

    /**
     * Cases à cocher des services que le magasin a déjà créés ; un service
     * qui n'y est pas encore se crée ici et y sera proposé la prochaine
     * fois (voir TrouverOuCreerServiceAction). Chaque service coché devient
     * une ligne dont le vendeur saisit le prix TTC.
     *
     * Ouvert à quiconque peut encaisser (voir canAccess()), création
     * comprise : un service n'est qu'un nom, sans prix ni stock, et le
     * vendeur doit pouvoir facturer une réparation qui n'est pas encore
     * dans la liste.
     */
    private function actionAjouterUnService(): Action
    {
        return Action::make('ajouterUnService')
            ->label('Ajouter un service')
            ->color('gray')
            ->size(ActionSize::Small)
            ->modalHeading('Ajouter un service')
            ->modalDescription('Cochez les services réalisés : chacun devient une ligne dont vous saisissez le prix TTC.')
            ->modalSubmitActionLabel('Ajouter')
            ->modalWidth(MaxWidth::Large)
            ->form([
                CheckboxList::make('services')
                    ->label('Services du magasin')
                    ->options(fn (): array => Service::query()->actifs()->orderBy('nom')->pluck('nom', 'id')->all())
                    ->searchable()
                    ->noSearchResultsMessage('Aucun service ne correspond.')
                    ->visible(fn (): bool => Service::query()->actifs()->exists())
                    ->required(fn (Get $get): bool => blank($get('nouveau_service')))
                    // Pas d'astérisque : un nouveau service tapé plus bas
                    // dispense de cocher.
                    ->markAsRequired(false)
                    ->validationMessages(['required' => 'Cochez au moins un service, ou écrivez le nom d\'un nouveau service.']),
                TextInput::make('nouveau_service')
                    ->label(fn (): string => Service::query()->actifs()->exists() ? 'Le service n\'est pas dans la liste ?' : 'Nom du service')
                    ->placeholder('Ex. : Remplacement écran')
                    ->helperText('Il sera ajouté à la liste : la prochaine fois, il suffira de le cocher.')
                    ->maxLength(Service::LONGUEUR_MAXIMALE_NOM)
                    ->rule(fn (): Closure => ServiceResource::regleNomRempli())
                    ->required(fn (): bool => ! Service::query()->actifs()->exists()),
            ])
            ->action(fn (array $data) => $this->ajouterLesServices($data));
    }

    /**
     * @param  array{services?: list<int|string>|null, nouveau_service?: string|null}  $data
     */
    private function ajouterLesServices(array $data): void
    {
        $identifiants = array_map('intval', $data['services'] ?? []);
        $nouveauService = Service::nettoyerNom((string) ($data['nouveau_service'] ?? ''));

        if ($nouveauService !== '') {
            try {
                $nouveau = app(TrouverOuCreerServiceAction::class)->handle($nouveauService);
            } catch (InvalidArgumentException $exception) {
                Notification::make()->title($exception->getMessage())->danger()->send();

                return;
            }

            if ($nouveau->wasRecentlyCreated) {
                Notification::make()
                    ->title("Service « {$nouveau->nom} » ajouté à la liste")
                    ->body('La prochaine fois, il suffira de le cocher.')
                    ->success()
                    ->send();
            } elseif ($nouveau->wasChanged('actif')) {
                Notification::make()
                    ->title("Service « {$nouveau->nom} » de nouveau proposé à la caisse")
                    ->success()
                    ->send();
            }

            $identifiants[] = $nouveau->id;
        }

        $services = Service::query()->whereKey($identifiants)->get()->keyBy('id');

        // Dans l'ordre où le vendeur les a cochés ; le nouveau service en
        // dernier, une seule fois même s'il était déjà coché.
        foreach (array_unique($identifiants) as $identifiant) {
            $service = $services->get($identifiant);

            if ($service !== null) {
                $this->ajouterUneLigne(['service_id' => $service->id, 'description' => $service->nom]);
            }
        }
    }

    /**
     * Comme le bouton natif du Repeater : la nouvelle ligne reçoit les
     * valeurs par défaut de ses champs (quantité 1, TVA 20 %...), puis
     * celles données ici.
     *
     * @param  array<string, mixed>  $valeurs
     */
    private function ajouterUneLigne(array $valeurs = []): void
    {
        /** @var Repeater $lignes */
        $lignes = $this->form->getComponent('data.lignes');

        $uuid = $lignes->generateUuid();

        $lignes->state([...($lignes->getState() ?? []), $uuid => []]);
        $lignes->getChildComponentContainer($uuid)->fill();

        if ($valeurs !== []) {
            $etat = $lignes->getState();
            $etat[$uuid] = [...$etat[$uuid], ...$valeurs];
            $lignes->state($etat);
        }

        $lignes->callAfterStateUpdated();
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
        $montants = collect($lignes)->map(fn (array $ligne) => DevisResource::montantsDeLigneSaisie($ligne, PrixReference::Ttc));

        try {
            return app(CalculateurLigne::class)->totaliser($montants)['total_ttc'];
        } catch (InvalidArgumentException) {
            // Montant absurde en cours de frappe : la validation le refusera.
            return 0.0;
        }
    }

    /**
     * Ce que la caisse encaisse vraiment : le total moins le crédit d'avoir
     * du client, que l'émission de la facture déduit automatiquement (voir
     * AppliquerAvoirsDisponiblesAction) -- sans quoi la monnaie à rendre
     * serait fausse du montant de ce crédit.
     */
    private function resteAPayer(Get $get): float
    {
        $totalTtc = $this->totalTtc($get('lignes') ?? []);

        try {
            return app(CalculateurLigne::class)->soustraire($totalTtc, $this->creditApplique($get, $totalTtc));
        } catch (InvalidArgumentException) {
            return 0.0;
        }
    }

    /**
     * Même montant que celui qu'appliquera AppliquerAvoirsDisponiblesAction :
     * tout le crédit disponible du client, dans la limite du total (jamais
     * pour le client comptoir, voir Client::soldeCrediteur()).
     */
    private function creditApplique(Get $get, float $totalTtc): float
    {
        $soldeCrediteur = Client::query()->find($get('client_id'))?->soldeCrediteur() ?? 0.0;

        return min($soldeCrediteur, $totalTtc);
    }

    private function detailCreditApplique(Get $get): ?string
    {
        $totalTtc = $this->totalTtc($get('lignes') ?? []);
        $credit = $this->creditApplique($get, $totalTtc);

        if ($credit <= 0) {
            return null;
        }

        return 'Total '.$this->formaterMontant($totalTtc).' moins '.$this->formaterMontant($credit).' de crédit d\'avoir du client.';
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
