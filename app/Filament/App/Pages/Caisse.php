<?php

namespace App\Filament\App\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Exceptions\RefusFiscalException;
use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Actions\EnregistrerVenteCaisseAction;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Enums\TypeDocument;
use App\Domain\Facturation\Models\AvoirAllocation;
use App\Domain\Facturation\Models\ClotureCaisse;
use App\Domain\Facturation\Models\Facture;
use App\Domain\Facturation\Models\PaiementAllocation;
use App\Domain\Facturation\Models\PanierCaisse;
use App\Domain\Facturation\Services\ApercuDuReglement;
use App\Domain\Facturation\Services\CalculateurLigne;
use App\Domain\Facturation\Services\PanierDeCaisse;
use App\Domain\Facturation\Services\ReglementComptant;
use App\Domain\Recherche\RechercheSansAccents;
use App\Domain\Stock\Exceptions\ConfirmationVenteSansStockRequiseException;
use App\Domain\Stock\Services\ConfirmationVenteSansStock;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Services\ActionsUniques;
use App\Filament\App\Resources\AvoirResource;
use App\Filament\App\Resources\ClientResource;
use App\Filament\App\Resources\DevisResource;
use App\Filament\Concerns\AvertitDUneVenteSansStock;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\RecoitLeClientChoisi;
use App\Filament\Concerns\RecoitLesProduitsChoisis;
use App\Filament\Concerns\SaisitUnReglementEnParts;
use Filament\Actions;
use Filament\Facades\Filament;
use Filament\Forms\Components\Actions\Action;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Exists;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use LogicException;

/**
 * Caisse rapide pour une vente comptoir : un vendeur encaisse directement
 * ici, sans passer par le formulaire complet d'une facture B2B (voir
 * FactureResource). Délègue à EnregistrerVenteCaisseAction la création,
 * l'émission et l'encaissement intégral en une seule opération -- une
 * vente comptoir n'est jamais "à régler plus tard".
 *
 * Réutilise les lignes de vente de DevisResource (même tableau des
 * produits, même douchette, même bouton « Ajouter un service », voir
 * AjouteDesLignesDeVente) et la loupe du champ « Client » des factures et
 * des devis (le tableau des clients, voir SelecteurDeClients) : aucune
 * raison de dupliquer cette logique pour un troisième formulaire de
 * lignes. Le client comptoir reste choisi par défaut ; un autre client
 * choisi présélectionne son mode de paiement préféré.
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
 * 320, avec le vendeur, le nombre d'articles et le montant, comme le dit
 * la fenêtre de confirmation) et chaque article retiré du panier ou dont
 * le prix baisse avant l'encaissement (JET 323), une fois la saisie du
 * prix terminée : le PU TTC se saisit en direct, et un chiffre en cours de
 * frappe ne s'inscrit jamais (voir PanierDeCaisse).
 *
 * Une fois la journée clôturée, la caisse n'encaisse plus aucune vente
 * jusqu'à minuit, pas même à un professionnel (voir journeeCloturee()) ;
 * le domaine refuse en plus tout ticket et tout avoir sur ticket (voir
 * ClotureCaisse::refuserUneVenteSiLaJourneeEstCloturee()).
 *
 * Tant que le magasin ne peut pas vendre (société en cours de
 * vérification, établissement pas encore confirmé, lecture seule), elle ne
 * s'ouvre pas : les autorisations d'émettre et d'encaisser y sont refusées
 * (voir ControleDesDroits), et le domaine refuse tout ticket et toute
 * facture (voir Magasin::verifierQuIlPeutVendre()).
 *
 * La vente se règle d'un seul geste, en un ou plusieurs modes (points 1
 * et 23, voir SaisitUnReglementEnParts) : le crédit d'avoir du client
 * s'affiche comme la part « Avoir (crédit du client) », imputée d'office ;
 * pour toute vente, quel qu'en soit le client, une part « Avoir » demande
 * le numéro d'un avoir fait au client comptoir (K6), dont le crédit
 * restant règle ce que le crédit du client et les bons saisis avant lui
 * laissent à payer ; les parts payées (espèces, carte bancaire, chèque,
 * virement, jamais le prélèvement) règlent exactement le reste, et seule
 * la part en espèces rend la monnaie. Tant qu'elle est la seule part payée
 * et que le vendeur n'en a pas saisi le montant, la première part suit ce
 * qui reste à payer (voir rendering()). L'encaissement relit le crédit et les bons
 * sous le verrou du registre et refuse un règlement devenu faux (voir
 * EmettreFactureAction).
 *
 * Une vente au-delà du stock (R1) est refusée à qui n'est pas autorisé à
 * vendre sans stock ; à qui l'est, la caisse montre d'abord
 * l'avertissement que renvoie l'encaissement (« Le stock de Câble USB
 * passera à −2 : confirmer la vente ? »), et n'encaisse qu'après sa
 * confirmation, renouvelée si le stock a encore baissé entre-temps (voir
 * confirmerLaVenteSansStockAction()).
 *
 * La vente porte le jeton de son panier (R3, voir PanierDeCaisse et
 * ActionsUniques), que la page garde ($jetonDeLaVente) : un encaissement
 * renvoyé après une réponse perdue retrouve le ticket déjà enregistré, et
 * la caisse rechargée, vide, rappelle la dernière vente enregistrée (voir
 * derniereVenteEnregistree()). Chaque nouvelle vente, encaissée ou
 * abandonnée, prend un nouveau jeton.
 */
class Caisse extends Page implements HasForms
{
    use AvertitDUneVenteSansStock, GereLesExceptionsMetier, InteractsWithForms, RecoitLeClientChoisi, RecoitLesProduitsChoisis, SaisitUnReglementEnParts;

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

    /**
     * Le jeton de la vente en cours (R3) : celui du panier enregistré, ou
     * celui que prendra le prochain panier. Jamais envoyé par le
     * navigateur : la page seule le change.
     */
    #[Locked]
    public string $jetonDeLaVente = '';

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

        $this->jetonDeLaVente = $panier->jeton ?? (string) Str::uuid();
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
     *
     * Pour un vendeur qui n'est pas autorisé à fixer les prix, chaque ligne
     * que l'encaissement refuserait reprend d'abord le prix du catalogue
     * (voir AjouteDesLignesDeVente::lignesAuxPrixPermis()) : un panier
     * repris après un changement du prix d'une fiche s'encaisse au nouveau
     * prix, qu'il voit avant d'encaisser.
     *
     * La seule part payée du règlement, tant que le vendeur n'en a pas
     * saisi le montant, prend ce qui reste à payer : le panier, le client
     * ou les bons d'avoir qui changent changent le montant qu'elle propose.
     */
    public function rendering(): void
    {
        $this->data['lignes'] = DevisResource::lignesAuxPrixPermis($this->data['lignes'] ?? [], $this->data['client_id'] ?? null);

        try {
            $this->enregistrerLePanier();
        } catch (LogicException|RefusFiscalException $refus) {
            static::notifierLeRefus($refus);

            $this->remplirFormulaire(app(PanierDeCaisse::class)->enCours(auth()->user()), $this->data['parts'] ?? null);
        }

        $this->suivreLeResteAPayer();
    }

    /**
     * La seule part payée du règlement (les parts « Avoir » mises à part),
     * tant que le vendeur n'en a pas saisi le montant, prend ce qui reste
     * à payer une fois le crédit du client et les bons imputés.
     */
    private function suivreLeResteAPayer(): void
    {
        $cle = $this->seulePartPayee();

        if ($cle === null || ($this->data['parts'][$cle]['montant_saisi'] ?? false)) {
            return;
        }

        $reste = $this->apercuDuReglement($this->data['lignes'] ?? [], $this->data['client_id'] ?? null, self::bonsDesParts($this->data['parts']))->resteARepartir;
        $this->data['parts'][$cle]['montant'] = $reste > 0 ? app(CalculateurLigne::class)->enDecimal($reste) : null;
    }

    /**
     * La clé de la seule part du règlement qui n'est pas une part « Avoir »
     * ; null s'il n'y en a aucune ou plusieurs.
     */
    private function seulePartPayee(): ?string
    {
        $parts = $this->data['parts'] ?? null;
        $payees = array_keys(array_filter(is_array($parts) ? $parts : [], fn (mixed $part): bool => is_array($part) && ($part['mode'] ?? null) !== self::PART_AVOIR));

        return count($payees) === 1 ? (string) $payees[0] : null;
    }

    /**
     * Le panier s'enregistre tel que le vendeur le voit, et la page reprend
     * le jeton du panier enregistré.
     *
     * @throws LogicException
     * @throws RefusFiscalException
     */
    private function enregistrerLePanier(): void
    {
        $panier = app(PanierDeCaisse::class)->enregistrer(auth()->user(), $this->jetonDeLaVente, $this->data['client_id'] ?? null, $this->data['lignes'] ?? []);

        if ($panier !== null) {
            $this->jetonDeLaVente = $panier->jeton;
        }
    }

    /**
     * La caisse passe à une nouvelle vente, sous un nouveau jeton : le
     * formulaire se vide.
     */
    private function commencerUneNouvelleVente(): void
    {
        $this->jetonDeLaVente = (string) Str::uuid();
        $this->remplirFormulaire(null);
    }

    /**
     * « Dernière vente enregistrée : ticket N° T-... », la dernière vente
     * que le vendeur a encaissée dans ce magasin (voir
     * ActionsUniques::dernierResultat()) : la caisse rechargée après une
     * réponse perdue, son panier vidé par l'encaissement, dit ainsi que la
     * vente est faite. Null s'il n'en a encaissé aucune depuis 30 jours.
     */
    public function derniereVenteEnregistree(): ?string
    {
        $facture = app(ActionsUniques::class)->dernierResultat(ActionsUniques::VENDRE_A_LA_CAISSE, auth()->user());

        if (! $facture instanceof Facture) {
            return null;
        }

        return 'Dernière vente enregistrée : '.($facture->serie() === TypeDocument::TicketCaisse ? 'ticket' : 'facture')." N° {$facture->numero}";
    }

    /**
     * Le formulaire avec le client et les lignes du panier, ou vide (le
     * client comptoir, aucune ligne) sans panier, et un règlement d'une
     * seule part, dans le mode de paiement préféré de ce client (B8),
     * sauf celui que le vendeur avait déjà saisi.
     *
     * @param  array<array-key, mixed>|null  $parts  le règlement déjà saisi, gardé
     */
    private function remplirFormulaire(?PanierCaisse $panier, ?array $parts = null): void
    {
        $clientId = $panier?->client_id ?? Client::query()->where('est_comptoir', true)->value('id');

        $this->form->fill([
            'client_id' => $clientId,
            'lignes' => array_values($panier->lignes ?? []),
            'parts' => $parts ?? [self::partVide(self::modePrefere($clientId))],
        ]);
    }

    /**
     * Une part dans ce mode, dont le montant suivra ce qui reste à payer.
     *
     * @return array{mode: string, montant: null, montant_saisi: false, reference: null, bon: null, montant_recu: null}
     */
    private static function partVide(ModePaiement $mode): array
    {
        return ['mode' => $mode->value, 'montant' => null, 'montant_saisi' => false, 'reference' => null, 'bon' => null, 'montant_recu' => null];
    }

    /**
     * Le client choisi donne son mode préféré (B8) à la seule part payée du
     * règlement ; les bons d'avoir saisis restent : ils règlent la vente de
     * tout client.
     */
    private function preselectionnerLeModePrefere(mixed $clientId): void
    {
        $cle = $this->seulePartPayee();

        if ($cle !== null) {
            $this->data['parts'][$cle]['mode'] = self::modePrefere($clientId)->value;
        }
    }

    /**
     * Le mode de paiement préféré du client, présélectionné à
     * l'encaissement (B8), sinon les espèces ; jamais le prélèvement, qui
     * ne règle pas une vente à la caisse.
     */
    private static function modePrefere(mixed $clientId): ModePaiement
    {
        $mode = blank($clientId) ? null : Client::withTrashed()->find($clientId)?->mode_paiement_prefere;

        return $mode !== null && in_array($mode, ModePaiement::aLaCaisse(), true) ? $mode : ModePaiement::Especes;
    }

    /**
     * L'aperçu du règlement de la vente saisie (voir ApercuDuReglement) :
     * le total TTC calculé par le même constructeur de lignes que la vente
     * encaissée (voir ConstructeurLigneDocument::apercu()), pour le client
     * choisi, puis son crédit et les bons d'avoir des parts « Avoir ». Le
     * client d'un panier archivé depuis garde son crédit affiché, comme son
     * nom (voir ClientResource::soldeCrediteurHelperText()).
     *
     * @param  mixed  $lignes  les lignes saisies
     * @param  list<string>  $bons  les numéros des bons d'avoir saisis
     */
    private function apercuDuReglement(mixed $lignes, mixed $clientId, array $bons): ApercuDuReglement
    {
        $totalTtc = app(CalculateurLigne::class)->enCentiemes(DevisResource::apercuDesLignes(is_array($lignes) ? $lignes : [], $clientId)->totaux['total_ttc']);
        $client = blank($clientId) || is_bool($clientId) || filter_var($clientId, FILTER_VALIDATE_INT) === false ? null : Client::withTrashed()->find((int) $clientId);

        return ApercuDuReglement::aLaCaisse($client, $totalTtc, $bons);
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
                    // La recherche cherche ce que cherche la loupe, sans
                    // casse ni accents.
                    ->getSearchResultsUsing(fn (string $search) => RechercheSansAccents::appliquer(Client::query(), $search, ClientResource::colonnesDeRecherche())
                        ->orderBy('nom')
                        ->orderBy('id')
                        ->limit(50)
                        ->pluck('nom', 'id'))
                    ->getOptionLabelUsing(fn ($value) => Client::withTrashed()->find($value)?->nom)
                    ->suffixAction(ClientResource::actionChercherUnClient())
                    ->rule(fn (): Exists => Client::regleExisteDansLeMagasin())
                    // Form explicitement modelé sur Client : ce Select
                    // n'utilise pas relationship(), qui le ferait sinon.
                    ->createOptionForm(fn (Form $form): Form => $form
                        ->model(Client::class)
                        ->schema(ClientResource::creationRapideSchema()))
                    ->createOptionModalHeading('Nouveau client')
                    ->createOptionAction(fn (Action $action) => $action->visible(auth()->user()->can(PermissionRegistry::CLIENT_CREATE)))
                    ->createOptionUsing(fn (array $data) => Client::create($data)->getKey())
                    ->afterStateUpdated(fn (mixed $state) => $this->preselectionnerLeModePrefere($state))
                    ->required(),
            ]),

            Section::make('Produits et services')->schema([
                ...DevisResource::lignesDeVente(),
                DevisResource::totauxDuDocument(parTaux: true),
            ]),

            Section::make('Règlement')->schema([
                Placeholder::make('total_a_payer')
                    ->label('Total à payer')
                    ->content(fn (Get $get): string => self::formaterCentimes($this->apercuDuReglement($get('lignes'), $get('client_id'), [])->total)),
                ...self::composantsDuReglement(
                    fn (Get $get, ?Model $record, string $racine): ApercuDuReglement => $this->apercuDuReglement($get($racine.'lignes'), $get($racine.'client_id'), self::bonsDesParts($get($racine.'parts'))),
                    fn (Get $get, ?Model $record, string $racine): ModePaiement => self::modePrefere($get($racine.'client_id')),
                    true,
                    'Avoir (crédit du client)',
                ),
            ]),
        ])->statePath('data');
    }

    /**
     * « Retour d'un ticket » ouvre la recherche par numéro de « Nouvel
     * avoir » (voir AvoirResource), à qui peut l'ouvrir et y retrouver ses
     * tickets (voir AvoirResource::peutOuvrirLaRecherche()) ; la vente en
     * cours reste dans son panier, et la caisse la reprend à son retour.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('retourDUnTicket')
                ->label('Retour d\'un ticket')
                ->icon('heroicon-o-receipt-refund')
                ->color('gray')
                ->visible(fn (): bool => AvoirResource::peutOuvrirLaRecherche())
                ->url(fn (): string => AvoirResource::getUrl('create')),
            Actions\Action::make('abandonnerLaVente')
                ->label('Abandonner la vente')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading('Abandonner la vente ?')
                ->modalDescription('Les lignes saisies sont effacées. Pour la sécurité de la caisse, l\'abandon est inscrit au journal des événements avec votre nom, le nombre d\'articles et le montant.')
                ->modalSubmitActionLabel('Oui, abandonner la vente')
                ->visible(fn (): bool => $this->lignesRenseignees() !== [])
                ->action(function (): void {
                    try {
                        app(PanierDeCaisse::class)->abandonner(auth()->user(), $this->jetonDeLaVente, $this->data['client_id'] ?? null, $this->data['lignes'] ?? []);
                    } catch (LogicException|RefusFiscalException $refus) {
                        static::notifierLeRefus($refus);

                        return;
                    }

                    $this->commencerUneNouvelleVente();

                    Notification::make()->title('Vente abandonnée')->success()->send();
                }),
        ];
    }

    /**
     * L'avertissement d'une vente sans stock (R1), ouvert par encaisser()
     * avec les manques que l'encaissement a trouvés et la confirmation qui
     * les accepte ; confirmé, la vente s'encaisse avec cette confirmation.
     * Le serveur revérifie l'autorisation et le stock : une confirmation
     * devenue insuffisante garde la fenêtre ouverte, avec le stock du
     * moment.
     */
    public function confirmerLaVenteSansStockAction(): Actions\Action
    {
        return Actions\Action::make('confirmerLaVenteSansStock')
            ->modalHeading('Vendre sans stock ?')
            ->modalIcon('heroicon-o-exclamation-triangle')
            ->modalIconColor('warning')
            ->modalDescription(fn (array $arguments): HtmlString => static::descriptionDeLAvertissement($arguments))
            ->modalSubmitActionLabel('Confirmer la vente')
            ->action(function (array $arguments, Actions\Action $action): void {
                try {
                    $confirmation = static::confirmationDesArguments($arguments);
                } catch (InvalidArgumentException $illisible) {
                    static::notifierLeRefus($illisible);

                    return;
                }

                $avertissement = $this->vendre($confirmation);

                if ($avertissement === null) {
                    return;
                }

                $nouveaux = static::argumentsDeLAvertissement($avertissement);
                $this->mountedActionsArguments[array_key_last($this->mountedActionsArguments)] = $nouveaux;
                $action->arguments($nouveaux);
                $action->halt();
            });
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
     * Une vente à un professionnel est une facture : tant que l'identité
     * légale de la société ou du magasin est incomplète, son émission sera
     * refusée (voir Magasin::verifierSonIdentiteLegale()), et la caisse le
     * dit dès le choix du client. Null pour un particulier, ou une identité
     * complète.
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
        return new HtmlString('<span style="color: #b91c1c; font-weight: 600;">'.e('Facture impossible : identité de la société incomplète ('.implode(', ', $manquantes).').').'</span>');
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
     * ne fait pas que désactiver le bouton. Une vente au-delà du stock
     * n'est encaissée qu'après l'avertissement confirmé (voir
     * confirmerLaVenteSansStockAction()).
     */
    public function encaisser(): void
    {
        $avertissement = $this->vendre(null);

        if ($avertissement !== null) {
            $this->mountAction('confirmerLaVenteSansStock', static::argumentsDeLAvertissement($avertissement));
        }
    }

    /**
     * Encaisse la vente du panier, avec la confirmation d'un avertissement
     * de vente sans stock s'il y en a une. Le panier s'enregistre juste
     * avant la vente : ce qu'il a perdu dans la même requête s'inscrit au
     * journal des événements avant d'être encaissé. Un refus s'affiche ;
     * un avertissement est rendu, pour que la fenêtre le montre.
     */
    private function vendre(?ConfirmationVenteSansStock $confirmation): ?ConfirmationVenteSansStockRequiseException
    {
        abort_unless(static::canAccess(), 403);

        $journeeCloturee = $this->journeeCloturee();

        if ($journeeCloturee !== null) {
            Notification::make()->title($journeeCloturee)->danger()->send();

            return null;
        }

        $data = $this->form->getState();

        $client = Client::query()->findOrFail($data['client_id']);

        try {
            $this->enregistrerLePanier();
            $facture = app(EnregistrerVenteCaisseAction::class)->handle(
                auth()->user(),
                $client,
                $data['lignes'],
                new ReglementComptant(self::partsPayees($data['parts'] ?? []), self::bonsDesParts($data['parts'] ?? [])),
                $confirmation,
                $this->jetonDeLaVente,
            );
        } catch (ConfirmationVenteSansStockRequiseException $avertissement) {
            return $avertissement;
        } catch (LogicException|AuthorizationException|RefusFiscalException $exception) {
            static::notifierLeRefus($exception);

            return null;
        }

        Notification::make()
            ->title("Vente encaissée : facture {$facture->numero}")
            ->body($this->resumeEncaissement($facture))
            ->success()
            ->send();

        $this->commencerUneNouvelleVente();

        return null;
    }

    /**
     * Ce qui a réglé la vente, part par part : chaque paiement dans son
     * mode, puis chaque crédit d'avoir imputé, celui du client ou un bon
     * d'avoir (« 50,00 € en espèces, 30,00 € par carte bancaire et 20,00 €
     * du bon d'avoir AV-... ») -- ce qui a RÉELLEMENT été encaissé, pas le
     * total brut de la facture, sous peine de fausser la caisse du vendeur
     * en fin de journée.
     */
    private function resumeEncaissement(Facture $facture): string
    {
        $calculateur = app(CalculateurLigne::class);
        $parts = [
            ...$facture->allocationsPaiement()->with('paiement')->get()->map(
                fn (PaiementAllocation $allocation): string => self::formaterCentimes($calculateur->enCentiemes($allocation->montant_alloue)).' '.$allocation->paiement->mode_paiement->dansUnePhrase(),
            ),
            ...$facture->allocationsAvoir()->with('avoir.client')->get()->map(
                fn (AvoirAllocation $allocation): string => self::formaterCentimes($calculateur->enCentiemes($allocation->montant_alloue)).(($allocation->avoir->client?->estComptoir() ?? false) ? ' du bon d\'avoir ' : ' du crédit d\'avoir ').$allocation->avoir->numero,
            ),
        ];

        if ($parts === []) {
            return 'Rien à encaisser.';
        }

        $derniere = array_pop($parts);

        return 'Réglée par '.($parts === [] ? $derniere : implode(', ', $parts).' et '.$derniere).'.';
    }
}
