<?php

namespace App\Filament\App\Resources;

use App\Domain\Contacts\Actions\VerifierNumeroTvaAction;
use App\Domain\Contacts\Enums\TypeClient;
use App\Domain\Contacts\Models\Client;
use App\Domain\Contacts\Models\ConsultationVies;
use App\Domain\Contacts\Rules\HorsDeLEntrepriseDuMagasin;
use App\Domain\Facturation\Enums\ModePaiement;
use App\Domain\Facturation\Rules\NumeroTvaAuFormat;
use App\Domain\Facturation\Services\TerritoireFiscal;
use App\Filament\App\Resources\ClientResource\Pages;
use App\Filament\Concerns\ArchiveLaFiche;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Concerns\SupprimeEnMasseLigneParLigne;
use Closure;
use Filament\Actions\MountableAction;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\HtmlString;

class ClientResource extends Resource
{
    use ArchiveLaFiche, GereLesExceptionsMetier, SupprimeEnMasseLigneParLigne;

    /**
     * Vendre à un autre magasin de la même société n'est pas une vente
     * (voir HorsDeLEntrepriseDuMagasin).
     */
    private const MESSAGE_PROPRE_ENTREPRISE = 'Ce SIREN est celui de votre propre entreprise : une opération entre magasins d\'une même société n\'est pas une vente.';

    private const MESSAGE_PROPRE_NUMERO_TVA = 'Ce n° de TVA est celui de votre propre entreprise : une opération entre magasins d\'une même société n\'est pas une vente.';

    protected static ?string $model = Client::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Ventes';

    protected static ?int $navigationSort = 1;

    protected static ?string $modelLabel = 'client';

    protected static ?string $pluralModelLabel = 'clients';

    /**
     * $get('type_client') renvoie l'instance de l'enum (pas sa valeur
     * scalaire) une fois le choix "type_client" live interagi -- état
     * "live" oblige -- d'où la normalisation ici : une comparaison directe
     * à TypeClient::Professionnel->value resterait silencieusement
     * toujours fausse dès qu'un utilisateur change réellement la valeur.
     * Le client comptoir reste un particulier, quelle que soit la valeur
     * forcée dans son type désactivé, qui n'est jamais enregistré. La
     * création rapide n'a pas de fiche : $record y est vide.
     */
    private static function estProfessionnel(Get $get, ?Client $record): bool
    {
        if ($record?->estComptoir()) {
            return false;
        }

        $type = $get('type_client');

        return ($type instanceof TypeClient ? $type : TypeClient::tryFrom((string) $type)) === TypeClient::Professionnel;
    }

    private static function visibleSiProfessionnel(): Closure
    {
        return fn (Get $get, ?Client $record): bool => static::estProfessionnel($get, $record);
    }

    /**
     * Une entreprise dont l'adresse est en France y est établie ; à
     * l'étranger, elle l'est si le magasin coche « Établi en France », pour
     * un établissement qu'elle a en France. Même règle que le modèle (voir
     * Client::normaliserSesIdentifiants()), lue ici sur la saisie : une
     * case forcée par une requête ne retire pas le SIREN à une adresse en
     * France.
     */
    private static function estEtabliEnFrance(Get $get): bool
    {
        return TerritoireFiscal::estEnFrance($get('pays')) || (bool) $get('etabli_en_france');
    }

    /**
     * Deux boutons côte à côte plutôt qu'un menu déroulant : un clic au
     * lieu de deux pendant une vente. Changer de type propose, comme le
     * pays, « Établi en France » depuis le pays de la fiche : un
     * particulier compte comme établi en France, ce qu'une entreprise
     * dont l'adresse est à l'étranger n'est pas d'office.
     */
    private static function champTypeClient(): Radio
    {
        return Radio::make('type_client')
            ->label('Type de client')
            ->options(TypeClient::class)
            ->inline()
            ->inlineLabel(false)
            ->default(TypeClient::Particulier)
            ->required()
            ->live()
            ->afterStateUpdated(fn (Get $get, Set $set) => $set('etabli_en_france', TerritoireFiscal::estEnFrance($get('pays'))));
    }

    /**
     * Le pays de l'adresse du client, enregistré en majuscules (voir
     * Client::booted()). Une adresse en France y établit le client ; à
     * chaque changement vers l'étranger, « Établi en France » se décoche,
     * et le magasin le coche pour une entreprise qui a un établissement en
     * France (voir champsProfessionnel()).
     */
    private static function champPays(): TextInput
    {
        return TextInput::make('pays')
            ->label('Pays')
            ->helperText('Code du pays à deux lettres : FR, BE, CH...')
            ->required()
            ->default('FR')
            ->maxLength(2)
            ->regex('/^[A-Za-z]{2}$/')
            ->validationMessages(['regex' => 'Le code du pays compte deux lettres : FR, BE, CH...'])
            ->extraInputAttributes(['style' => 'text-transform: uppercase'])
            ->live(onBlur: true)
            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('etabli_en_france', TerritoireFiscal::estEnFrance($state)));
    }

    /**
     * Les identifiants d'un client professionnel, mêmes règles dans la
     * fiche et dans la création rapide des devis, des factures et de la
     * Caisse : établi en France (toujours, pour une adresse en France), il
     * a un SIREN, ou un SIRET, qui commence par lui ; établi ailleurs, un
     * n° de TVA ou un autre identifiant. Ni son SIREN, ni son SIRET, ni son
     * n° de TVA ne sont ceux de l'entreprise du magasin. Le format seul est
     * contrôlé : aucune clé de contrôle, que les n° du bac à sable
     * SUPER PDP (000000001, 000000002) ne respectent pas.
     *
     * « Établi en France » vaut oui ou non : obligatoire, il refuse une
     * valeur vide forcée par une requête, sans astérisque pour un
     * interrupteur qui a toujours une valeur à l'écran. Désactivé pour une
     * adresse en France, il n'est pas enregistré, et le modèle le coche.
     *
     * @return array<Component>
     */
    private static function champsProfessionnel(): array
    {
        $visibleSiProfessionnel = static::visibleSiProfessionnel();

        return [
            Toggle::make('etabli_en_france')
                ->label('Établi en France')
                ->helperText(fn (Get $get): string => TerritoireFiscal::estEnFrance($get('pays'))
                    ? 'Son adresse est en France : il y est établi, et son SIREN l\'identifie.'
                    : 'Cochez pour une entreprise étrangère qui a un établissement en France : son SIREN remplace alors son n° de TVA ou un autre identifiant.')
                ->default(true)
                ->disabled(fn (Get $get): bool => TerritoireFiscal::estEnFrance($get('pays')))
                ->required()
                ->markAsRequired(false)
                ->live()
                ->visible($visibleSiProfessionnel),
            TextInput::make('siren')
                ->label('SIREN')
                ->maxLength(9)
                ->regex('/^\d{9}$/')
                ->required(fn (Get $get): bool => static::estEtabliEnFrance($get) && blank($get('siret')))
                ->rule(new HorsDeLEntrepriseDuMagasin(self::MESSAGE_PROPRE_ENTREPRISE))
                ->validationMessages([
                    'regex' => 'Le SIREN compte 9 chiffres, sans espace.',
                    'required' => 'Le SIREN (ou le SIRET) est obligatoire pour un client professionnel établi en France.',
                ])
                ->visible($visibleSiProfessionnel),
            TextInput::make('siret')
                ->label('SIRET')
                ->maxLength(14)
                ->regex('/^\d{14}$/')
                ->rule(fn (Get $get): Closure => function (string $attribute, mixed $siret, Closure $fail) use ($get): void {
                    if (filled($get('siren')) && ! str_starts_with((string) $siret, (string) $get('siren'))) {
                        $fail('Le SIRET commence par les 9 chiffres du SIREN.');
                    }
                })
                ->rule(new HorsDeLEntrepriseDuMagasin(self::MESSAGE_PROPRE_ENTREPRISE))
                ->validationMessages(['regex' => 'Le SIRET compte 14 chiffres, sans espace.'])
                ->visible($visibleSiProfessionnel),
            // Saisi avec ses séparateurs s'il le faut : il s'enregistre sans
            // eux (voir NumeroTvaNormalise).
            TextInput::make('numero_tva')
                ->label('N° TVA intracommunautaire')
                ->maxLength(20)
                ->rule(new NumeroTvaAuFormat)
                ->rule(new HorsDeLEntrepriseDuMagasin(self::MESSAGE_PROPRE_NUMERO_TVA))
                ->visible($visibleSiProfessionnel),
            TextInput::make('identifiant_etranger')
                ->label('Autre identifiant (entreprise étrangère)')
                ->maxLength(50)
                ->required(fn (Get $get): bool => blank($get('numero_tva')))
                ->validationMessages(['required' => 'Indiquez le n° de TVA ou un autre identifiant de ce client établi hors de France.'])
                ->visible(fn (Get $get, ?Client $record): bool => static::estProfessionnel($get, $record) && ! static::estEtabliEnFrance($get)),
        ];
    }

    /**
     * L'adresse à laquelle partent ses factures et ses relances (voir
     * EnvoyerDocumentAction) : sans commentaire, guillemets ni domaine sans
     * point, que le serveur d'envoi refuserait.
     */
    private static function champEmail(): TextInput
    {
        return TextInput::make('email')
            ->type('email')
            ->rule('email:strict')
            ->maxLength(150)
            ->validationMessages(['email' => 'Cette adresse e-mail n\'est pas valide.']);
    }

    /**
     * Formulaire allégé utilisé dans la modale "+" du Select client_id de
     * DevisResource/FactureResource et de la Caisse (createOptionForm) :
     * un vendeur en train de facturer n'a besoin que de l'essentiel pour
     * créer un nouveau client à la volée, pas de toute la fiche complète --
     * sauf pour un professionnel, qui a besoin de ses identifiants et de
     * son adresse dès la création.
     *
     * @return array<Component>
     */
    public static function creationRapideSchema(): array
    {
        $visibleSiProfessionnel = static::visibleSiProfessionnel();

        return [
            TextInput::make('nom')->required()->maxLength(150)->columnSpanFull(),
            self::champTypeClient(),
            self::champPays()->visible($visibleSiProfessionnel),
            ...self::champsProfessionnel(),
            TextInput::make('adresse')->maxLength(255)->columnSpanFull()->visible($visibleSiProfessionnel),
            TextInput::make('complement_adresse')->label('Complément d\'adresse')->maxLength(255)->columnSpanFull()->visible($visibleSiProfessionnel),
            TextInput::make('code_postal')->label('Code postal')->maxLength(10)->visible($visibleSiProfessionnel),
            TextInput::make('ville')->maxLength(100)->visible($visibleSiProfessionnel),
            TextInput::make('telephone')->tel()->maxLength(30),
            self::champEmail(),
        ];
    }

    /**
     * Le client comptoir (voir Client::estComptoir()) garde son nom et son
     * type : un champ désactivé n'est jamais enregistré.
     */
    public static function form(Form $form): Form
    {
        $estComptoir = fn (?Client $record): bool => $record?->estComptoir() ?? false;

        return $form->schema([
            Section::make('Identité')->schema([
                TextInput::make('nom')
                    ->required()
                    ->maxLength(150)
                    ->columnSpanFull()
                    ->disabled($estComptoir)
                    ->helperText(fn (?Client $record): ?string => $record?->estComptoir()
                        ? 'La fiche des ventes anonymes au comptoir : elle ne se renomme pas, reste un particulier et ne s\'archive jamais.'
                        : null),
                self::champTypeClient()->disabled($estComptoir),
                self::champPays(),
                ...self::champsProfessionnel(),
            ])->columns(2),

            Section::make('Coordonnées')->schema([
                TextInput::make('adresse')->maxLength(255)->columnSpanFull(),
                TextInput::make('complement_adresse')->label('Complément d\'adresse')->maxLength(255)->columnSpanFull(),
                TextInput::make('code_postal')->label('Code postal')->maxLength(10),
                TextInput::make('ville')->maxLength(100),
                TextInput::make('telephone')->tel()->maxLength(30),
                self::champEmail(),
            ])->columns(2),

            Section::make('Paiement et notes')->schema([
                TextInput::make('delai_paiement_jours')
                    ->label('Délai de paiement (jours)')
                    ->helperText('Vide : délai par défaut du magasin (Paramètres > Factures).')
                    ->integer()
                    ->minValue(0)
                    ->maxValue(365),
                Select::make('mode_paiement_prefere')
                    ->label('Mode de paiement préféré')
                    ->options(ModePaiement::class)
                    ->enum(ModePaiement::class),
                Textarea::make('notes')->columnSpanFull(),
            ])->columns(2),

            self::sectionDesVerificationsVies(),
        ]);
    }

    /**
     * Les vérifications du n° de TVA du client sur VIES, en lecture seule :
     * ce que dit la dernière pour son n° actuel, puis toutes, de la plus
     * récente à la plus ancienne, anciens n° compris. Relues à chaque
     * affichage : une vérification faite depuis la page (voir
     * verifierSurVies()) y apparaît aussitôt. Montrée dès que le n° se
     * vérifie sur VIES, ou qu'une vérification existe, sur la fiche en
     * lecture seule (voir ViewClient), que voit quiconque peut voir le
     * client, comme sur la page de modification.
     */
    private static function sectionDesVerificationsVies(): Section
    {
        return Section::make('Vérifications VIES')
            ->description('Le service de la Commission européenne qui dit si un n° de TVA de l\'Union est valide. Chaque vérification est conservée comme preuve.')
            ->schema([
                Placeholder::make('consultations_vies')
                    ->hiddenLabel()
                    ->content(fn (Client $record): View => view('filament.app.clients.consultations-vies', [
                        'numeroTva' => $record->numero_tva,
                        'derniere' => $record->derniereConsultationVies(),
                        'consultations' => $record->consultationsVies()->latest('consultee_le')->latest('id')->get(),
                    ])),
            ])
            ->visible(fn (?Client $record): bool => $record !== null
                && ($record->aUnNumeroTvaVerifiableSurVies() || $record->consultationsVies()->exists()));
    }

    /**
     * Le bouton « Vérifier sur VIES », dans la liste des clients et sur la
     * fiche (voir ViewClient et EditClient) : pour un professionnel dont le
     * n° de TVA est d'un État membre de l'Union ou d'Irlande du Nord, à qui
     * peut modifier le client (voir ClientPolicy::update()). C'est le n°
     * enregistré qui se vérifie, et la notification le cite. La
     * vérification se conserve (voir VerifierNumeroTvaAction) ; VIES
     * indisponible, l'écran le dit, et rien n'est conservé.
     *
     * @template TAction of MountableAction
     *
     * @param  TAction  $action
     * @return TAction
     */
    public static function verifierSurVies(MountableAction $action): MountableAction
    {
        return $action
            ->label('Vérifier sur VIES')
            ->icon('heroicon-o-check-badge')
            ->visible(fn (Client $record): bool => $record->aUnNumeroTvaVerifiableSurVies())
            ->authorize('update')
            ->action(fn (Client $record) => static::executerSansCasser(
                fn () => static::notifierLaVerification(app(VerifierNumeroTvaAction::class)->handle($record)),
            ));
    }

    private static function notifierLaVerification(ConsultationVies $consultation): void
    {
        if (! $consultation->valide) {
            Notification::make()->title("N° de TVA {$consultation->numero_tva} inconnu de VIES : la vente reste à la TVA française.")->warning()->send();

            return;
        }

        Notification::make()->title("N° de TVA {$consultation->numero_tva} valide sur VIES ({$consultation->reference()}).")->success()->send();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('nom')->rechercheSansAccents()->sortable(),
                Tables\Columns\TextColumn::make('type_client')->label('Type')->badge()->sortable(),
                Tables\Columns\TextColumn::make('email')->rechercheSansAccents(),
                Tables\Columns\TextColumn::make('telephone'),
                Tables\Columns\TextColumn::make('ville'),
                // Un avoir n'est jamais remboursé par ce logiciel (pas
                // d'intégration bancaire) : ce qu'il n'a pas imputé à son
                // émission sur les factures impayées de ce client, ou ce
                // qu'une contre-passation lui a rendu, reste un crédit
                // (voir Client::soldeCrediteur()) -- affiché ici en
                // permanence tant qu'il n'est pas intégralement consommé.
                Tables\Columns\TextColumn::make('solde_crediteur')
                    ->label('Solde créditeur')
                    ->state(fn (Client $record) => $record->soldeCrediteur())
                    ->money('EUR')
                    ->color(fn (Client $record) => $record->soldeCrediteur() > 0 ? 'warning' : 'gray')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label('Créé le')->dateTime('d/m/Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('type_client')->label('Type')->options(TypeClient::class),
                static::filtreDesArchives(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                static::verifierSurVies(Tables\Actions\Action::make('verifier_sur_vies')),
                static::actionRestaurer(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    static::archivageEnMasse(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListClients::route('/'),
            'create' => Pages\CreateClient::route('/create'),
            'view' => Pages\ViewClient::route('/{record}'),
            'edit' => Pages\EditClient::route('/{record}/edit'),
        ];
    }

    /**
     * Texte d'aide affiché sous le Select client des pages de création de
     * vente (Devis/Facture/Caisse), pour que le vendeur voie le crédit
     * disponible avant même de valider -- il sera appliqué
     * automatiquement à l'émission (voir AppliquerAvoirsDisponiblesAction),
     * y compris pour le client d'un brouillon archivé depuis. Les
     * formulaires de paiement ont leur propre texte (voir
     * PaiementResource::aideDuCreditDisponible()).
     */
    public static function soldeCrediteurHelperText(mixed $clientId): ?Htmlable
    {
        $solde = self::soldeCrediteurDe($clientId);

        return $solde === null ? null : self::enEvidence('Crédit disponible pour ce client : '.number_format($solde, 2, ',', ' ').' € (sera appliqué automatiquement).');
    }

    /**
     * Le crédit d'avoir disponible du client (voir Client::soldeCrediteur()),
     * null quand il n'en a pas.
     */
    public static function soldeCrediteurDe(mixed $clientId): ?float
    {
        if (blank($clientId)) {
            return null;
        }

        $solde = Client::withTrashed()->find($clientId)?->soldeCrediteur();

        return $solde !== null && $solde > 0 ? $solde : null;
    }

    /**
     * Couleur directement en ligne, pas une classe Tailwind : ce message
     * doit attirer l'œil du vendeur, pas se fondre dans le texte d'aide
     * gris habituel -- et ne dépend ainsi jamais de ce que le build CSS a
     * ou non conservé.
     */
    public static function enEvidence(string $texte): Htmlable
    {
        return new HtmlString('<span style="color: #c2410c; font-weight: 600;">'.e($texte).'</span>');
    }
}
