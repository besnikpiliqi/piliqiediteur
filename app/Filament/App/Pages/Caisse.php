<?php

namespace App\Filament\App\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Enums\CodeEvenement;
use App\Domain\CaisseSecurisee\Exceptions\RefusFiscalException;
use App\Domain\CaisseSecurisee\Services\RegistreFiscal;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Tenancy\Services\TenantContext;
use App\Filament\App\Resources\ClientResource;
use App\Filament\App\Resources\DevisResource;
use App\Filament\Concerns\GereLesExceptionsMetier;
use Filament\Actions;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Exceptions\Cancel;
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
 * Une vente qui ne va pas jusqu'à l'encaissement laisse une trace au
 * journal des événements du magasin : son abandon (« Abandonner la
 * vente », JET 320) et chaque ligne renseignée retirée du panier avant
 * l'encaissement (JET 323). Une fois la journée clôturée, la caisse
 * n'encaisse plus jusqu'à minuit (voir journeeCloturee()).
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

    public function mount(): void
    {
        $this->remplirFormulaireVide();
    }

    private function remplirFormulaireVide(): void
    {
        $this->form->fill([
            'client_id' => Client::query()->proposables()->where('est_comptoir', true)->value('id'),
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
                    // Un client désactivé n'est plus proposé pour une vente
                    // (voir ProposableSiActif).
                    ->getSearchResultsUsing(fn (string $search) => Client::query()
                        ->proposables()
                        ->where('nom', 'like', "%{$search}%")
                        ->limit(50)
                        ->pluck('nom', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Client::find($value)?->nom)
                    ->rule(fn (): Exists => Client::regleProposableDansLeMagasin())
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
                ...DevisResource::lignesDeVente(fn (array $ligne) => $this->journaliserLeRetrait($ligne)),
                DevisResource::totauxDuDocument(),
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
                    $lignes = $this->lignesRenseignees();

                    $this->journaliser(CodeEvenement::TransactionAbandonnee, [
                        'origine' => 'caisse',
                        'nombre_lignes' => count($lignes),
                        'total_ttc' => $this->enCentimes($this->totalTtc($lignes)),
                    ]);

                    $this->remplirFormulaireVide();

                    Notification::make()->title('Vente abandonnée')->success()->send();
                }),
        ];
    }

    /**
     * Le refus qu'opposerait la caisse à une vente maintenant, la journée
     * étant clôturée (voir ClotureCaisse::refusDUneVente()) : affiché en
     * tête de la caisse, « Encaisser » désactivé. Null tant que la journée
     * est ouverte.
     */
    public function journeeCloturee(): ?string
    {
        return ClotureCaisse::refusDUneVente(now());
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
     * Une ligne renseignée retirée du panier avant l'encaissement (voir
     * AjouteDesLignesDeVente::lignesDeVente()) : ce qu'elle vendait, telle
     * que le vendeur l'avait saisie, le prix en centimes.
     *
     * @param  array<string, mixed>  $ligne
     *
     * @throws Cancel le journal des événements ne s'écrit pas : la ligne reste
     */
    private function journaliserLeRetrait(array $ligne): void
    {
        $this->journaliser(CodeEvenement::LigneRetiree, [
            'produit_id' => filled($ligne['produit_id'] ?? null) ? (int) $ligne['produit_id'] : null,
            'service_id' => filled($ligne['service_id'] ?? null) ? (int) $ligne['service_id'] : null,
            'description' => filled($ligne['description'] ?? null) ? (string) $ligne['description'] : null,
            'quantite' => is_numeric($ligne['quantite'] ?? null) ? (int) $ligne['quantite'] : null,
            'prix_unitaire_ttc' => is_numeric($ligne['prix_unitaire_ttc'] ?? null) ? $this->enCentimes((float) $ligne['prix_unitaire_ttc']) : null,
        ]);
    }

    /**
     * Inscrit l'événement au journal des événements du magasin, sous le
     * verrou de son registre fiscal (voir RegistreFiscal::sousVerrou()). Si
     * le journal ne s'écrit pas (clé de signature absente, horloge qui
     * recule), le vendeur en lit la raison et le geste n'a pas lieu.
     *
     * @param  array<string, int|string|null>  $details
     *
     * @throws Cancel
     */
    private function journaliser(CodeEvenement $code, array $details): void
    {
        $registre = app(RegistreFiscal::class);

        try {
            $registre->sousVerrou(app(TenantContext::class)->magasin(), fn () => $registre->journaliser($code, $details));
        } catch (LogicException|RefusFiscalException $refus) {
            static::notifierLeRefus($refus);

            throw new Cancel;
        }
    }

    /**
     * Un montant du panier en centimes, comme tout montant du registre
     * fiscal ; null s'il est hors des limites d'un montant.
     */
    private function enCentimes(float $montant): ?int
    {
        try {
            return app(CalculateurLigne::class)->enCentiemes(number_format($montant, 2, '.', ''));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Une méthode Livewire s'appelle directement, page déjà ouverte :
     * Filament revérifie canAccess() à chaque requête, et la caisse le
     * revérifie elle-même avant d'émettre et d'encaisser, sans dépendre de
     * ce comportement du panneau.
     */
    public function encaisser(): void
    {
        abort_unless(static::canAccess(), 403);

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
        } catch (LogicException|RefusFiscalException $exception) {
            static::notifierLeRefus($exception);

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
        $montants = collect($lignes)->map(fn (array $ligne) => DevisResource::montantsDeLigneSaisie($ligne));

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
