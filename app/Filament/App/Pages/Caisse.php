<?php

namespace App\Filament\App\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Exceptions\RefusFiscalException;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\Avoir;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\PanierCaisse;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\PanierDeCaisse;
use App\Domain\Recherche\RechercheSansAccents;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ReglagesCommunsDeLaSociete;
use App\Filament\App\Resources\ClientResource;
use App\Filament\App\Resources\DevisResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use Closure;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\Rules\Exists;
use InvalidArgumentException;
use LogicException;

/**
 * Caisse rapide pour une vente comptoir : un vendeur encaisse directement
 * ici, sans passer par le formulaire complet d'une facture B2B (voir
 * FactureResource). Délègue à EnregistrerVenteCaisseAction la création,
 * l'émission et l'encaissement intégral en une seule opération -- une
 * vente comptoir n'est jamais "à régler plus tard".
 *
 * Réutilise les lignes de vente de DevisResource (même recherche produit
 * par nom/référence/code-barres, mêmes boutons « Ajouter un produit » et
 * « Ajouter un service », voir AjouteDesLignesDeVente) : aucune raison de
 * dupliquer cette logique pour un troisième formulaire de lignes.
 *
 * La caisse n'introduit aucune permission dédiée : elle n'est qu'une façon
 * plus rapide de faire ce qu'un vendeur peut déjà faire ailleurs, créer
 * une facture, l'émettre et enregistrer son paiement. Elle exige donc ces
 * trois permissions (voir canAccess()).
 *
 * Le panier se garde côté serveur à chaque requête de la page (voir
 * rendering() et PanierDeCaisse) : la caisse reprend une vente commencée
 * quand elle se rouvre, après un rechargement de la page comme après la
 * fermeture de l'onglet. Une vente qui ne va pas jusqu'à l'encaissement
 * laisse donc toujours une trace au journal des événements du magasin :
 * son abandon (« Abandonner la vente », ou la clôture de sa journée, JET
 * 320) et chaque article retiré du panier avant l'encaissement (JET 323).
 *
 * Une fois la journée clôturée, la caisse n'encaisse plus aucune vente
 * jusqu'à minuit, pas même à un professionnel (voir journeeCloturee()) ;
 * le domaine refuse en plus tout ticket et tout avoir sur ticket (voir
 * ClotureCaisse::refuserUneVenteSiLaJourneeEstCloturee()).
 *
 * Tant que les réglages communs du magasin ne sont pas ceux du magasin
 * principal de sa société, elle n'encaisse rien non plus : elle dit quel
 * réglage diffère et quelle valeur prendre (voir reglagesNonAlignes()),
 * et le domaine refuse tout ticket et toute facture (voir
 * EmettreFactureAction).
 *
 * Pour toute vente, quel qu'en soit le client, « Utiliser un bon
 * d'avoir » demande le numéro d'un avoir fait au client comptoir (K6) :
 * son crédit restant est déduit de ce que le crédit d'avoir du client
 * laisse à payer, puis imputé sur la facture à l'encaissement, qui relit
 * le bon sous le verrou du registre (voir EnregistrerVenteCaisseAction).
 */
class Caisse extends Page implements HasForms
{
    use GereLesExceptionsMetier, InteractsWithForms;

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
        return auth()->user()?->can([
            PermissionRegistry::FACTURE_CREATE,
            PermissionRegistry::FACTURE_EMETTRE,
            PermissionRegistry::PAIEMENT_CREER,
        ]) ?? false;
    }

    /**
     * La caisse s'ouvre sur la vente que le vendeur avait commencée, s'il y
     * en a une : il l'encaisse ou l'abandonne.
     */
    public function mount(): void
    {
        $panier = app(PanierDeCaisse::class)->enCours(auth()->user());

        $this->remplirFormulaire($panier);

        if ($panier !== null) {
            Notification::make()
                ->title('Vente en cours reprise')
                ->body('Encaissez-la, ou abandonnez-la : son abandon est inscrit au journal des événements.')
                ->warning()
                ->send();
        }
    }

    /**
     * Juste avant l'affichage, à chaque requête de la page : le panier
     * enregistré suit celui que voit le vendeur, et ce qu'il a perdu
     * s'inscrit au journal des événements (voir PanierDeCaisse). Si le
     * journal ne s'écrit pas, le vendeur en lit la raison et retrouve le
     * panier enregistré : aucun retrait n'échappe au journal.
     */
    public function rendering(): void
    {
        try {
            $this->enregistrerLePanier();
        } catch (LogicException|RefusFiscalException $refus) {
            static::notifierLeRefus($refus);

            $this->remplirFormulaire(app(PanierDeCaisse::class)->enCours(auth()->user()), [
                'mode_paiement' => $this->data['mode_paiement'] ?? ModePaiement::Especes->value,
                'montant_recu' => $this->data['montant_recu'] ?? null,
                'reference' => $this->data['reference'] ?? null,
                'bon_d_avoir' => $this->data['bon_d_avoir'] ?? null,
            ]);
        }
    }

    /**
     * @throws LogicException
     * @throws RefusFiscalException
     */
    private function enregistrerLePanier(): void
    {
        app(PanierDeCaisse::class)->enregistrer(auth()->user(), $this->data['client_id'] ?? null, $this->data['lignes'] ?? []);
    }

    /**
     * Le formulaire avec le client et les lignes du panier, ou vide (le
     * client comptoir, aucune ligne) sans panier, et le mode de paiement
     * préféré de ce client (B8).
     *
     * @param  array<string, mixed>  $paiement
     */
    private function remplirFormulaire(?PanierCaisse $panier, array $paiement = []): void
    {
        $clientId = $panier?->client_id ?? Client::query()->where('est_comptoir', true)->value('id');

        $this->form->fill([
            'client_id' => $clientId,
            'lignes' => array_values($panier->lignes ?? []),
            'mode_paiement' => self::modePrefere($clientId)->value,
            'montant_recu' => null,
            'reference' => null,
            'bon_d_avoir' => null,
            ...$paiement,
        ]);
    }

    /**
     * Le mode de paiement préféré du client, présélectionné à
     * l'encaissement (B8), sinon les espèces.
     */
    private static function modePrefere(mixed $clientId): ModePaiement
    {
        return (blank($clientId) ? null : Client::withTrashed()->find($clientId)?->mode_paiement_prefere) ?? ModePaiement::Especes;
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Client')->schema([
                Select::make('client_id')
                    ->label('Client')
                    ->live()
                    ->helperText(fn (Get $get) => $this->factureImpossible($get('client_id'))
                        ?? ClientResource::soldeCrediteurHelperText($get('client_id'))
                        ?? 'Par défaut : le client comptoir générique, pour une vente anonyme.')
                    ->searchable()
                    // Un client archivé n'est plus proposé pour une vente, ni
                    // accepté ; celui d'un panier enregistré avant son
                    // archivage reste affiché, le temps d'en choisir un autre.
                    // La recherche ignore la casse et les accents.
                    ->getSearchResultsUsing(fn (string $search) => RechercheSansAccents::appliquer(Client::query(), $search, ['nom'])
                        ->orderBy('nom')
                        ->orderBy('id')
                        ->limit(50)
                        ->pluck('nom', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Client::withTrashed()->find($value)?->nom)
                    ->rule(fn (): Exists => Client::regleExisteDansLeMagasin())
                    // Form explicitement modelé sur Client : ce Select
                    // n'utilise pas relationship(), qui le ferait sinon.
                    ->createOptionForm(fn (Form $form): Form => $form
                        ->model(Client::class)
                        ->schema(ClientResource::creationRapideSchema()))
                    ->createOptionModalHeading('Nouveau client')
                    ->createOptionAction(fn (Action $action) => $action->visible(auth()->user()->can(PermissionRegistry::CLIENT_CREATE)))
                    ->createOptionUsing(fn (array $data) => Client::create($data)->getKey())
                    // Le bon d'avoir saisi reste : il règle la vente de tout
                    // client.
                    ->afterStateUpdated(fn (mixed $state, Set $set) => $set('mode_paiement', self::modePrefere($state)->value))
                    ->required(),
            ]),

            Section::make('Produits et services')->schema([
                ...DevisResource::lignesDeVente(),
                DevisResource::totauxDuDocument(),
            ]),

            Section::make('Paiement')->schema([
                Placeholder::make('total_a_payer')
                    // Ses actions exigent une clé : son chemin, comme celle
                    // d'un champ.
                    ->key(fn (Placeholder $component): string => $component->getStatePath())
                    ->label('Total à payer')
                    ->content(fn (Get $get) => $this->formaterMontant($this->resteAPayer($get)))
                    ->helperText(fn (Get $get) => $this->detailCreditApplique($get))
                    ->hintActions([self::actionUtiliserUnBonDAvoir(), self::actionRetirerLeBonDAvoir()]),
                // Posé par « Utiliser un bon d'avoir » : l'encaissement le
                // revérifie (voir EnregistrerVenteCaisseAction).
                Hidden::make('bon_d_avoir')->rules(['nullable', 'string', 'max:40']),
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

    /**
     * Le numéro d'un avoir fait au client comptoir vaut bon d'avoir (K6),
     * pour la vente de tout client : le numéro saisi doit désigner un
     * avoir du client comptoir du magasin dont il reste du crédit.
     * L'encaissement le revérifie sous le verrou du registre (voir
     * EnregistrerVenteCaisseAction).
     */
    private static function actionUtiliserUnBonDAvoir(): Action
    {
        return Action::make('utiliserUnBonDAvoir')
            ->label('Utiliser un bon d\'avoir')
            ->icon('heroicon-o-ticket')
            ->modalHeading('Utiliser un bon d\'avoir')
            ->modalDescription('Saisissez le n° de l\'avoir remis au client : son crédit restant est déduit du total à payer.')
            ->modalSubmitActionLabel('Utiliser')
            ->modalWidth(MaxWidth::Medium)
            ->form([
                TextInput::make('numero')
                    ->label('N° de l\'avoir')
                    ->placeholder('AV-...')
                    ->required()
                    ->maxLength(40)
                    ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (self::bonDAvoirUtilisable($value) === null) {
                            $fail(Avoir::BON_D_AVOIR_INTROUVABLE);
                        }
                    }),
            ])
            ->visible(fn (Get $get): bool => blank($get('bon_d_avoir')))
            ->action(fn (array $data, Set $set) => $set('bon_d_avoir', mb_strtoupper(trim($data['numero']))));
    }

    private static function actionRetirerLeBonDAvoir(): Action
    {
        return Action::make('retirerLeBonDAvoir')
            ->label('Retirer le bon d\'avoir')
            ->icon('heroicon-o-x-mark')
            ->color('gray')
            ->visible(fn (Get $get): bool => filled($get('bon_d_avoir')))
            ->action(fn (Set $set) => $set('bon_d_avoir', null));
    }

    /**
     * L'avoir du client comptoir de ce numéro, s'il lui reste du crédit
     * (voir Avoir::scopeBonDAvoir()) ; null sinon.
     */
    private static function bonDAvoirUtilisable(mixed $numero): ?Avoir
    {
        $bon = is_string($numero) && filled($numero) ? Avoir::query()->bonDAvoir($numero)->first() : null;

        return $bon !== null && $bon->montantNonAlloue() > 0 ? $bon : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('abandonnerLaVente')
                ->label('Abandonner la vente')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Abandonner la vente ?')
                ->modalDescription('Les lignes saisies sont effacées, et l\'abandon de la vente est inscrit au journal des événements de la caisse.')
                ->modalSubmitActionLabel('Oui, abandonner la vente')
                ->visible(fn (): bool => $this->lignesRenseignees() !== [])
                ->action(function (): void {
                    try {
                        app(PanierDeCaisse::class)->abandonner(auth()->user(), $this->data['client_id'] ?? null, $this->data['lignes'] ?? []);
                    } catch (LogicException|RefusFiscalException $refus) {
                        static::notifierLeRefus($refus);

                        return;
                    }

                    $this->remplirFormulaire(null);

                    Notification::make()->title('Vente abandonnée')->success()->send();
                }),
        ];
    }

    /**
     * Le refus qu'oppose la caisse à toute vente maintenant, la journée
     * étant clôturée (voir ClotureCaisse::refusDUneVente()) : affiché en
     * tête de la caisse, « Encaisser » désactivé, et revérifié par
     * encaisser(). Null tant que la journée est ouverte.
     */
    public function journeeCloturee(): ?string
    {
        return ClotureCaisse::refusDUneVente(now(), ClotureCaisse::derniereJourneeCloturee());
    }

    /**
     * Le refus qu'oppose le magasin à tout ticket et à toute facture, ses
     * réglages communs n'étant pas ceux du magasin principal de sa société
     * (voir ReglagesCommunsDeLaSociete::refusDEmission()) : affiché en tête
     * de la caisse, « Encaisser » désactivé. Null quand il est aligné.
     */
    public function reglagesNonAlignes(): ?string
    {
        /** @var Magasin $magasin */
        $magasin = Filament::getTenant();

        return app(ReglagesCommunsDeLaSociete::class)->refusDEmission($magasin);
    }

    /**
     * Une vente à un professionnel est une facture : tant que l'identité
     * légale du magasin est incomplète, son émission sera refusée (voir
     * Magasin::verifierSonIdentiteLegale()), et la caisse le dit dès le
     * choix du client. Null pour un particulier, ou une identité complète.
     */
    private function factureImpossible(mixed $clientId): ?Htmlable
    {
        $client = blank($clientId) ? null : Client::withTrashed()->find($clientId);

        if (! $client?->estProfessionnel()) {
            return null;
        }

        /** @var Magasin $magasin */
        $magasin = Filament::getTenant();
        $manquantes = $magasin->mentionsLegalesManquantes();

        if ($manquantes === []) {
            return null;
        }

        // En couleur, comme le crédit du client : ce message doit arrêter
        // le vendeur avant qu'il ne saisisse la vente.
        return new HtmlString('<span style="color: #b91c1c; font-weight: 600;">'.e('Facture impossible : identité du magasin incomplète ('.implode(', ', $manquantes).').').'</span>');
    }

    /**
     * Les lignes du panier où un produit ou un service est choisi : une
     * ligne encore vide n'est pas une vente commencée.
     *
     * @return list<array<string, mixed>>
     */
    private function lignesRenseignees(): array
    {
        return array_values(array_filter(
            $this->data['lignes'] ?? [],
            fn (mixed $ligne): bool => is_array($ligne) && (filled($ligne['produit_id'] ?? null) || filled($ligne['service_id'] ?? null)),
        ));
    }

    /**
     * Une méthode Livewire s'appelle directement, page déjà ouverte :
     * Filament revérifie canAccess() à chaque requête, et la caisse le
     * revérifie elle-même avant d'émettre et d'encaisser, sans dépendre de
     * ce comportement du panneau ; de même pour la journée clôturée, qui
     * ne fait pas que désactiver le bouton. Le panier s'enregistre juste
     * avant la vente : ce qu'il a perdu dans la même requête s'inscrit au
     * journal des événements avant d'être encaissé.
     */
    public function encaisser(): void
    {
        abort_unless(static::canAccess(), 403);

        $journeeCloturee = $this->journeeCloturee();

        if ($journeeCloturee !== null) {
            Notification::make()->title($journeeCloturee)->danger()->send();

            return;
        }

        $data = $this->form->getState();

        $client = Client::query()->findOrFail($data['client_id']);
        $modePaiement = $this->modePaiementDepuisValeur($data['mode_paiement']);

        try {
            $this->enregistrerLePanier();
            $facture = app(EnregistrerVenteCaisseAction::class)->handle(
                $client,
                $data['lignes'],
                $modePaiement,
                $data['reference'] ?? null,
                $data['bon_d_avoir'] ?? null,
            );
        } catch (LogicException|RefusFiscalException $exception) {
            static::notifierLeRefus($exception);

            return;
        }

        Notification::make()
            ->title("Vente encaissée : facture {$facture->numero}")
            ->body($this->resumeEncaissement($facture))
            ->success()
            ->send();

        $this->remplirFormulaire(null);
    }

    /**
     * Le crédit disponible du client (voir AppliquerAvoirsDisponiblesAction)
     * et le bon d'avoir présenté au comptoir peuvent déjà avoir couvert tout
     * ou partie de la vente au moment de l'émission, avant même que la
     * caisse n'encaisse quoi que ce soit en espèces/CB -- le message doit
     * refléter ce qui a RÉELLEMENT été encaissé, pas le total brut de la
     * facture, sous peine de fausser la caisse du vendeur en fin de
     * journée.
     */
    private function resumeEncaissement(Facture $facture): string
    {
        $credite = $facture->montantCrediteParAvoir();

        if ($credite <= 0) {
            return $this->formaterMontant((float) $facture->total_ttc).' encaissé.';
        }

        $avoirs = $facture->numerosDesAvoirsImputes();
        $numeros = implode(', ', $avoirs);
        $paye = $facture->montantPaye();

        if ($paye <= 0) {
            return "Réglée intégralement par avoir ({$this->formaterMontant($credite)}, {$numeros}).";
        }

        $parLesAvoirs = count($avoirs) > 1 ? 'par les avoirs' : 'par l\'avoir';

        return "{$this->formaterMontant($paye)} encaissé ({$this->formaterMontant($credite)} crédité {$parLesAvoirs} {$numeros}).";
    }

    /**
     * Calculé par le même constructeur de lignes que la vente encaissée
     * (voir EnregistrerVenteCaisseAction et
     * ConstructeurLigneDocument::apercu()), pour le client choisi et le
     * régime de TVA du magasin : le total -- et donc la monnaie à rendre
     * affichée au vendeur -- est au centime près celui qui sera encaissé.
     */
    private function totalTtc(Get $get): float
    {
        try {
            return app(CalculateurLigne::class)->totaliser(DevisResource::apercuDesLignes($get('lignes') ?? [], $get('client_id')))['total_ttc'];
        } catch (InvalidArgumentException) {
            // Montant absurde en cours de frappe : la validation le refusera.
            return 0.0;
        }
    }

    /**
     * Ce que la caisse encaisse vraiment : le total moins le crédit d'avoir
     * du client et le bon d'avoir présenté, que l'émission de la facture
     * déduit automatiquement (voir AppliquerAvoirsDisponiblesAction et
     * EnregistrerVenteCaisseAction) -- sans quoi la monnaie à rendre serait
     * fausse du montant de ces crédits.
     */
    private function resteAPayer(Get $get): float
    {
        $totalTtc = $this->totalTtc($get);

        try {
            return app(CalculateurLigne::class)->soustraire($totalTtc, ...array_values($this->creditsAppliques($get, $totalTtc)));
        } catch (InvalidArgumentException) {
            return 0.0;
        }
    }

    /**
     * Les montants qu'imputera l'émission, dans son ordre et dans la
     * limite du total : d'abord le crédit disponible du client (jamais
     * pour le client comptoir, voir Client::soldeCrediteur()), puis le
     * crédit restant du bon d'avoir présenté, sur ce que le premier laisse
     * à payer (voir EmettreFactureAction). Celui d'un panier dont le
     * client a été archivé depuis reste affiché, comme son nom et le texte
     * d'aide du crédit (voir ClientResource::soldeCrediteurHelperText()).
     *
     * @return array{client: float, bon: float}
     */
    private function creditsAppliques(Get $get, float $totalTtc): array
    {
        $creditDuClient = min(Client::withTrashed()->find($get('client_id'))?->soldeCrediteur() ?? 0.0, $totalTtc);
        $creditDuBon = min(
            self::bonDAvoirUtilisable($get('bon_d_avoir'))?->montantNonAlloue() ?? 0.0,
            app(CalculateurLigne::class)->soustraire($totalTtc, $creditDuClient),
        );

        return ['client' => $creditDuClient, 'bon' => $creditDuBon];
    }

    /**
     * Le détail du total à payer : ce qu'en déduisent le crédit du client
     * et le bon d'avoir présenté, ou, en couleur, le refus d'un bon
     * d'avoir épuisé depuis qu'il a été saisi (par une autre caisse).
     */
    private function detailCreditApplique(Get $get): string|Htmlable|null
    {
        $bonDAvoir = $get('bon_d_avoir');

        if (filled($bonDAvoir) && self::bonDAvoirUtilisable($bonDAvoir) === null) {
            return ClientResource::enEvidence(Avoir::BON_D_AVOIR_INTROUVABLE);
        }

        $totalTtc = $this->totalTtc($get);
        $credits = $this->creditsAppliques($get, $totalTtc);
        $deductions = array_filter([
            $credits['client'] > 0 ? $this->formaterMontant($credits['client']).' de crédit d\'avoir du client' : null,
            $credits['bon'] > 0 ? $this->formaterMontant($credits['bon'])." du bon d'avoir {$bonDAvoir}" : null,
        ]);

        if ($deductions === []) {
            return null;
        }

        return 'Total '.$this->formaterMontant($totalTtc).' moins '.implode(' et ', $deductions).'.';
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
