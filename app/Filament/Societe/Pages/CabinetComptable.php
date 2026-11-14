<?php

namespace App\Filament\Societe\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\AjouterUnExpertComptableAction;
use App\Domain\Cabinets\Actions\DeclarerSonExpertComptableAction;
use App\Domain\Cabinets\Actions\DesignerUnCabinetAction;
use App\Domain\Cabinets\Actions\InviterSonCabinetACreerSonEspaceAction;
use App\Domain\Cabinets\Actions\MettreFinAuLienAction;
use App\Domain\Cabinets\Actions\ReglerLesAutorisationsDuCabinetAction;
use App\Domain\Cabinets\Actions\RetirerLaDemandeDeLienAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\OrigineDuLien;
use App\Domain\Cabinets\Models\Cabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\ExpertsComptablesDeclares;
use App\Domain\Cabinets\Services\RechercheDesCabinets;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Enums\TypeDActivite;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Support\CompteConnecte;
use App\Domain\Tenancy\Support\ValeurRequise;
use App\Domain\Tenancy\Support\ValeurSimple;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\Concerns\ConfirmeLAdresseDuLien;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Filament\Societe\Resources\MembreResource;
use App\Integrations\Courriel\CourrielNonParti;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use UnexpectedValueException;

/**
 * La page « Cabinet comptable » de l'espace d'une société, pour son
 * propriétaire (voir SocietePolicy::gererSonCabinet() : jamais un cabinet,
 * ni une société révoquée ou close) :
 * - le cabinet lié, s'il y en a un : sa dénomination, son SIREN, sa ville,
 *   son n° d'inscription à l'Ordre, la date du lien et les personnes du
 *   cabinet qui ont accès (leurs noms seulement) ; ce que la société lui
 *   accorde, par case, en deux groupes, « Consulter » et, pour une société
 *   qui a des articles, « Achats et stock » (voir
 *   ReglerLesAutorisationsDuCabinetAction) ; mettre fin au lien ;
 * - les demandes en cours : celles des cabinets (« Répondre » mène à la
 *   page de rattachement, voir RattachementDUnCabinet), et celle que la
 *   société a envoyée, qu'elle retire ;
 * - « Désigner votre cabinet », par la recherche des cabinets vérifiés (voir
 *   RechercheDesCabinets et DesignerUnCabinetAction), et l'invitation de son
 *   expert-comptable à créer son espace (voir
 *   InviterSonCabinetACreerSonEspaceAction) ;
 * - l'expert-comptable déclaré par le SIREN de son cabinet, quand ce
 *   cabinet n'a pas d'espace vérifié (voir DeclarerSonExpertComptableAction) :
 *   ses personnes, ses autorisations et ses magasins ; la déclaration et
 *   l'ajout d'une personne passent par une fenêtre qui répète l'adresse en
 *   gras avant l'envoi du lien (voir ConfirmeLAdresseDuLien), et le SIREN
 *   d'un cabinet déjà vérifié propose « Lui demander le lien ».
 * Les lectures du cabinet sont celles que CLAUDE.md permet : jamais
 * l'identifiant ni l'adresse d'un de ses comptes. Lier un cabinet ne
 * valide jamais une société qui attend son code.
 *
 * @property Form $form
 */
class CabinetComptable extends Page implements HasForms
{
    public const TOUS_LES_MAGASINS = 'tous';

    public const SELECTION_DE_MAGASINS = 'selection';

    use ConfirmeLAdresseDuLien, GereLesExceptionsMetier, InteractsWithForms;

    public const AIDE_TELECHARGEMENTS = 'Jamais accordé par défaut : vous seul le cochez. Votre cabinet n\'y reçoit que les documents que ses autres autorisations lui laissent voir.';

    public const TEXTE_JAMAIS = 'Jamais la vente, l\'encaissement, la caisse, les comptes ni les réglages de la société.';

    public const TEXTE_DE_LA_DECLARATION = 'Votre expert-comptable n\'a pas encore d\'espace PILIQI ? Déclarez-le par le SIREN de son cabinet : PILIQI crée un compte pour chaque personne que vous nommez, qui choisit son mot de passe par un lien valable 7 jours envoyé à son adresse professionnelle. Le jour où son cabinet ouvre son espace et est vérifié, il est relié à votre société avec les mêmes autorisations, et ces comptes se ferment.';

    public const TEXTE_DE_LA_CONFIRMATION = 'Le lien du mot de passe part à :adresse. La personne qui le suit choisit son mot de passe et entre dans vos magasins avec les autorisations de votre déclaration : vérifiez cette adresse avant l\'envoi.';

    public const TEXTE_EN_ATTENTE = 'Dès que ce cabinet sera inscrit et vérifié sur PILIQI, il sera relié à votre société avec ces autorisations, et ces comptes seront fermés, tant qu\'au moins une de ces personnes a un compte actif.';

    protected static ?string $navigationIcon = 'heroicon-o-briefcase';

    protected static ?string $navigationLabel = 'Cabinet comptable';

    protected static ?string $title = 'Cabinet comptable';

    protected static string $view = 'filament.societe.pages.cabinet-comptable';

    public string $recherche = '';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $societe = Filament::getTenant();

        return $societe instanceof Societe && (auth()->user()?->can('gererSonCabinet', $societe) ?? false);
    }

    public function mount(): void
    {
        $this->remplir();
    }

    /**
     * Le formulaire des autorisations et des magasins, tel que la base les
     * garde pour le lien en cours (cabinet lié ou expert-comptable déclaré).
     */
    private function remplir(): void
    {
        $lien = $this->lienEnCours();

        $this->form->fill($lien === null
            ? ['portee' => self::TOUS_LES_MAGASINS, 'magasins' => [], 'consultations' => [], 'achats' => [], 'telechargements' => []]
            : self::etatDuLien($lien, $this->societe()->type_activite));
    }

    /**
     * L'état du formulaire des autorisations et des magasins de ce lien
     * (un cabinet lié ou une déclaration), tel que la base les garde : son
     * choix de magasins, et ses autorisations réparties entre leurs trois
     * groupes, une case par paire « voir » et « voir toutes ». Ici, et sur
     * la page de rattachement pour la demande du cabinet que la société a
     * déclaré (« par défaut » y veut dire « celles de votre déclaration »).
     *
     * @return array{portee: string, magasins: list<string>, consultations: list<string>, achats: list<string>, telechargements: list<string>}
     */
    public static function etatDuLien(LienCabinet $lien, TypeDActivite $type): array
    {
        $accordees = $lien->autorisations();

        return [
            'portee' => $lien->tous_les_magasins ? self::TOUS_LES_MAGASINS : self::SELECTION_DE_MAGASINS,
            'magasins' => array_map(strval(...), $lien->magasinsChoisis()),
            'consultations' => PermissionRegistry::casesDuCabinet(array_values(array_intersect($accordees, PermissionRegistry::parDefautDuCabinet($type)))),
            'achats' => array_values(array_intersect($accordees, PermissionRegistry::ajoutablesAuCabinet($type))),
            'telechargements' => array_values(array_intersect($accordees, PermissionRegistry::cocheesParLeProprietaire($type))),
        ];
    }

    /**
     * L'état du formulaire d'un lien qui naît avec les autorisations par
     * défaut : tous les magasins, présents et futurs, et toutes les
     * consultations, sans le téléchargement des documents par période.
     *
     * @return array{portee: string, magasins: list<string>, consultations: list<string>, achats: list<string>, telechargements: list<string>}
     */
    public static function etatParDefaut(TypeDActivite $type): array
    {
        return ['portee' => self::TOUS_LES_MAGASINS, 'magasins' => [], 'consultations' => PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet($type)), 'achats' => [], 'telechargements' => []];
    }

    /**
     * Les magasins qu'un lien ouvre, dits en toutes lettres : « tous vos
     * magasins réels, présents et futurs », ou les noms des magasins
     * choisis.
     */
    public static function magasinsOuvertsEnToutesLettres(LienCabinet $lien, Societe $societe): string
    {
        if ($lien->tous_les_magasins) {
            return 'tous vos magasins réels, présents et futurs';
        }

        $noms = ValeursLues::textes($societe->magasinsReels()->whereKey($lien->magasinsChoisis())->orderBy('nom')->orderBy('id')->pluck('nom'));

        return (count($noms) > 1 ? 'les magasins ' : 'le magasin ').implode(', ', $noms);
    }

    /**
     * Les autorisations cochées, des deux groupes.
     *
     * @return list<string>
     */
    private function autorisationsCochees(): array
    {
        return self::autorisationsCocheesDans($this->form->getState());
    }

    /**
     * Les deux champs du choix des magasins que voit le cabinet, ici comme
     * sur la page de rattachement (décision de l'éditeur du 11/11/2026) :
     * « Tous les magasins, présents et futurs », d'un clic, ou une
     * sélection cochée magasin par magasin, parmi les magasins réels de la
     * société.
     *
     * @return list<Component>
     */
    public static function champsDesMagasins(Societe $societe, Closure $visible): array
    {
        return [
            Radio::make('portee')
                ->label('Magasins que voit votre cabinet')
                ->options([self::TOUS_LES_MAGASINS => 'Tous les magasins, présents et futurs', self::SELECTION_DE_MAGASINS => 'Seulement les magasins cochés ci-dessous'])
                ->default(self::TOUS_LES_MAGASINS)
                ->required()
                ->live()
                ->visible($visible),
            CheckboxList::make('magasins')
                ->label('Magasins choisis')
                ->helperText('Un magasin non coché n\'est ni listé ni ouvert pour votre cabinet. Les mêmes autorisations valent dans chaque magasin coché.')
                ->options(fn (): array => $societe->magasinsReels()->orderBy('nom')->orderBy('id')->pluck('nom', 'id')->map(fn (mixed $nom): string => ValeursLues::texte($nom))->all())
                ->columns(2)
                ->visible(fn (Get $get): bool => $visible() && $get('portee') === self::SELECTION_DE_MAGASINS),
        ];
    }

    /**
     * Les magasins choisis d'un formulaire : null pour tous les magasins,
     * présents et futurs ; sinon les identifiants cochés, une sélection
     * vide étant refusée par l'action (voir LienCabinet::choisirLesMagasins()).
     *
     * @param  array<array-key, mixed>  $etat
     * @return list<int>|null
     */
    public static function magasinsChoisisDans(array $etat): ?array
    {
        if (($etat['portee'] ?? self::TOUS_LES_MAGASINS) === self::TOUS_LES_MAGASINS) {
            return null;
        }

        return array_values(array_map(static fn (mixed $magasin): int => (int) ValeurSimple::enTexte($magasin), ValeursLues::tableau($etat['magasins'] ?? [])));
    }

    /**
     * Les autorisations cochées des trois groupes (consultations, achats
     * et téléchargements) d'un formulaire, réunies, sans doublon, ici
     * comme sur la page de rattachement.
     *
     * @param  array<array-key, mixed>  $etat
     * @return list<string>
     *
     * @throws UnexpectedValueException un groupe qui n'est pas une liste de cases
     */
    public static function autorisationsCocheesDans(array $etat): array
    {
        $cochees = [];

        foreach (['consultations', 'achats', 'telechargements'] as $groupe) {
            foreach (ValeursLues::tableau($etat[$groupe] ?? []) as $autorisation) {
                $cochees[] = ValeurSimple::enTexte($autorisation);
            }
        }

        return array_values(array_unique($cochees));
    }

    public function societe(): Societe
    {
        return ValeursLues::instance(Filament::getTenant(), Societe::class);
    }

    /**
     * Le lien en cours de la société : son cabinet lié, ou son
     * expert-comptable déclaré, dont la déclaration attend l'espace de son
     * cabinet ; les autorisations et les magasins se règlent de même pour
     * l'un et l'autre.
     */
    public function lienEnCours(): ?LienCabinet
    {
        return LienCabinet::query()->enCours()->first();
    }

    public function form(Form $form): Form
    {
        $type = $this->societe()->type_activite;
        $libelles = PermissionRegistry::libellesDuCabinet($type);

        return $form
            ->schema([
                ...self::champsDesMagasins($this->societe(), fn (): bool => $this->lienEnCours() !== null),
                CheckboxList::make('consultations')
                    ->label('Consulter')
                    ->options(array_intersect_key($libelles, array_flip(PermissionRegistry::parDefautDuCabinet($type))))
                    ->columns(2)
                    ->hidden(fn (): bool => $this->lienEnCours() === null),
                CheckboxList::make('achats')
                    ->label('Achats et stock')
                    ->options(array_intersect_key($libelles, array_flip(PermissionRegistry::ajoutablesAuCabinet($type))))
                    ->columns(2)
                    ->hidden(fn (): bool => $this->lienEnCours() === null || PermissionRegistry::ajoutablesAuCabinet($type) === []),
                CheckboxList::make('telechargements')
                    ->label('Téléchargement des documents par période')
                    ->helperText(self::AIDE_TELECHARGEMENTS)
                    ->options(array_intersect_key($libelles, array_flip(PermissionRegistry::cocheesParLeProprietaire($type))))
                    ->hidden(fn (): bool => $this->lienEnCours() === null),
                Placeholder::make('jamais')->hiddenLabel()->content(self::TEXTE_JAMAIS)->hidden(fn (): bool => $this->lienEnCours() === null),
            ])
            ->statePath('data');
    }

    public function enregistrerLesAutorisationsAction(): Action
    {
        return Action::make('enregistrerLesAutorisations')
            ->label('Enregistrer')
            ->requiresConfirmation(fn (): bool => $this->autorisationsCochees() === [])
            ->modalDescription(fn (): ?string => $this->autorisationsCochees() !== [] ? null : ($this->lienEnCours()?->estDeclare() === true
                ? 'Sans aucune autorisation, les comptes de votre expert-comptable déclaré voient votre identité, vos réglages fiscaux et les magasins choisis, mais n\'ouvrent plus aucun document, aucune fiche ni aucun téléchargement.'
                : 'Sans aucune autorisation, votre cabinet reste lié et voit votre identité, vos réglages fiscaux et vos magasins, mais n\'ouvre plus aucun document, aucune fiche ni aucun téléchargement.'))
            ->visible(fn (): bool => $this->lienEnCours() !== null)
            ->action(function (): void {
                static::executerSansCasser(function (): void {
                    $lien = $this->lienEnCours() ?? throw new LogicException('Votre société n\'a plus de cabinet lié ni d\'expert-comptable déclaré.');
                    $etat = $this->form->getState();
                    app(ReglerLesAutorisationsDuCabinetAction::class)->handle(CompteConnecte::exiger(), $lien, self::autorisationsCocheesDans($etat), self::magasinsChoisisDans($etat));
                    Notification::make()->title($lien->estDeclare() ? 'Les autorisations de votre expert-comptable sont enregistrées.' : 'Les autorisations de votre cabinet sont enregistrées.')->success()->send();
                });
            });
    }

    public function mettreFinAction(): Action
    {
        return Action::make('mettreFin')
            ->label(fn (): string => $this->lienEnCours()?->estDeclare() === true ? 'Retirer votre expert-comptable' : 'Mettre fin au lien')
            ->color('danger')
            ->icon('heroicon-o-link-slash')
            ->requiresConfirmation()
            ->modalDescription(fn (): string => $this->lienEnCours()?->estDeclare() === true
                ? 'Les comptes de '.implode(', ', $this->personnesDeLaDeclaration()->map(fn (User $compte): string => $compte->name)->all()).' sont retirés aussitôt ; leur historique reste.'
                : 'L\'accès de votre cabinet à votre société s\'arrête aussitôt ; il en est prévenu.')
            ->visible(fn (): bool => $this->lienEnCours() !== null)
            ->action(function (): void {
                static::executerSansCasser(function (): void {
                    $lien = $this->lienEnCours() ?? throw new LogicException('Votre société n\'a plus de cabinet lié ni d\'expert-comptable déclaré.');
                    $declaration = $lien->estDeclare();
                    app(MettreFinAuLienAction::class)->handle(CompteConnecte::exiger(), $lien);
                    $this->remplir();
                    Notification::make()->title($declaration ? 'Votre expert-comptable n\'est plus déclaré : ses comptes sont retirés.' : 'Le lien avec votre cabinet a pris fin.')->success()->send();
                });
            });
    }

    public function retirerLaDemandeAction(): Action
    {
        return Action::make('retirerLaDemande')
            ->label('Retirer')
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (array $arguments): void {
                static::executerSansCasser(function () use ($arguments): void {
                    $lien = LienCabinet::query()->parUuid(ValeurSimple::enTexte($arguments['uuid'] ?? ''))->firstOrFail();
                    app(RetirerLaDemandeDeLienAction::class)->handle(CompteConnecte::exiger(), $lien);
                    Notification::make()->title('Votre demande est retirée.')->success()->send();
                });
            });
    }

    public function demanderLeLienAction(): Action
    {
        return Action::make('demanderLeLien')
            ->label('Demander le lien')
            ->requiresConfirmation()
            ->modalDescription(fn (): string => 'Le propriétaire de ce cabinet reçoit votre demande et y répond dans les 7 jours ; tant qu\'il ne l\'a pas acceptée, il n\'a aucun accès à votre société.'
                .($this->lienEnCours()?->estDeclare() === true ? ' Son acceptation remplacera votre expert-comptable déclaré et fermera ses comptes, sauf si c\'est son cabinet : il est alors relié aussitôt.' : ''))
            ->action(function (array $arguments): void {
                static::executerSansCasser(function () use ($arguments): void {
                    $cabinet = app(RechercheDesCabinets::class)->pour($this->recherche)->firstWhere('uuid', ValeurSimple::enTexte($arguments['uuid'] ?? ''))
                        ?? throw new LogicException(DesignerUnCabinetAction::MESSAGE_CABINET_NON_LIABLE);
                    $this->designer($cabinet);
                });
            });
    }

    /**
     * « Lui demander le lien », au pied de la fenêtre « Déclarer votre
     * expert-comptable », quand le SIREN saisi est celui d'un cabinet déjà
     * vérifié sur PILIQI, qui ne se déclare pas (décision de l'éditeur du
     * 01/11/2026, P1 a ; cahier du lot EC2, R5) : la désignation de ce
     * cabinet, retrouvé par la recherche des cabinets vérifiés, puis la
     * fenêtre se ferme.
     */
    private function actionLuiDemanderLeLien(): Action
    {
        return Action::make('luiDemanderLeLien')
            ->label('Lui demander le lien')
            ->icon('heroicon-o-link')
            ->color('primary')
            ->visible(fn (): bool => $this->societe()->estValidee() && $this->cabinetVerifieDuSiren($this->saisieDeLaFenetreDuGeste()['siren'] ?? null) !== null)
            ->cancelParentActions()
            ->action(function (): void {
                static::executerSansCasser(function (): void {
                    $this->designer($this->cabinetVerifieDuSiren($this->saisieDeLaFenetreDuGeste()['siren'] ?? null) ?? throw new LogicException(DesignerUnCabinetAction::MESSAGE_CABINET_NON_LIABLE));
                });
            });
    }

    /**
     * Le cabinet vérifié de ce SIREN, tel que la recherche des cabinets le
     * montre (voir RechercheDesCabinets) ; null pour un SIREN qui n'a pas
     * neuf chiffres ou qu'aucun cabinet vérifié ne porte : un cabinet
     * inscrit sans être vérifié ne se révèle jamais.
     */
    private function cabinetVerifieDuSiren(mixed $siren): ?Societe
    {
        $siren = is_string($siren) ? (string) preg_replace('/\s+/', '', $siren) : '';

        return preg_match('/^\d{9}$/', $siren) === 1 ? app(RechercheDesCabinets::class)->pour($siren)->first() : null;
    }

    /**
     * Désigne ce cabinet vérifié (voir DesignerUnCabinetAction) et dit ce
     * qui en est né : la déclaration de son SIREN rattachée aussitôt, ses
     * comptes fermés ; le lien actif, quand le cabinet avait déjà demandé le
     * rattachement ; sinon la demande envoyée, qui attend sa réponse.
     */
    private function designer(Societe $cabinet): void
    {
        $noms = implode(', ', $this->personnesDeLaDeclaration()->map(fn (User $compte): string => $compte->name)->all());
        $lien = app(DesignerUnCabinetAction::class)->handle(CompteConnecte::exiger(), $this->societe(), Societe::query()->whereKey($cabinet->getKey())->firstOrFail());
        $this->remplir();

        Notification::make()->success()->title(match (true) {
            $lien->estActif() && $lien->origine === OrigineDuLien::DeclarationDuClient => "Votre expert-comptable déclaré est désormais relié à votre société par son cabinet : les comptes de {$noms} sont fermés.",
            $lien->estActif() => 'Ce cabinet est désormais votre cabinet.',
            default => 'Votre demande est envoyée au cabinet.',
        })->send();
    }

    public function inviterSonExpertAction(): Action
    {
        return Action::make('inviterSonExpert')
            ->label('L\'inviter à créer son espace')
            ->icon('heroicon-o-envelope')
            ->modalDescription('Un e-mail l\'invite à inscrire son cabinet : le type « Cabinet d\'expertise comptable » est définitif, et son cabinet sera vérifié (code d\'activité 69.20Z, inscription à l\'Ordre) avant tout lien.')
            ->form([
                TextInput::make('email')->label('Son adresse e-mail')->email()->required()->maxLength(255),
            ])
            ->action(function (array $data): void {
                static::executerSansCasser(function () use ($data): void {
                    app(InviterSonCabinetACreerSonEspaceAction::class)->handle(CompteConnecte::exiger(), $this->societe(), ValeurSimple::enTexte($data['email']));
                    Notification::make()->title('L\'invitation est envoyée.')->success()->send();
                });
            });
    }

    /**
     * « Déclarer votre expert-comptable » : par le SIREN de son cabinet, le
     * nom d'une personne et son adresse professionnelle saisie deux fois,
     * avec les autorisations et les magasins de la déclaration, en
     * redonnant son mot de passe (voir DeclarerSonExpertComptableAction).
     * Avec un lien en cours ou une demande envoyée, une case confirme le
     * remplacement. Le SIREN d'un cabinet déjà vérifié sur PILIQI ne se
     * déclare pas : la fenêtre le dit et propose « Lui demander le lien »
     * (R5). « Continuer » ouvre la fenêtre qui répète l'adresse en gras,
     * dont le bouton déclare (voir ConfirmeLAdresseDuLien). Le jeton
     * d'écran, tiré à l'ouverture, rend sans effet une demande répétée.
     */
    public function declarerAction(): Action
    {
        $type = $this->societe()->type_activite;
        $libelles = PermissionRegistry::libellesDuCabinet($type);
        $visible = fn (): bool => $this->societe()->estValidee();

        return Action::make('declarer')
            ->label(fn (): string => $this->lienEnCours() === null ? 'Déclarer votre expert-comptable' : 'Déclarer un autre expert-comptable')
            ->icon('heroicon-o-user-plus')
            ->visible($visible)
            ->modalHeading('Déclarer votre expert-comptable')
            ->modalDescription(self::TEXTE_DE_LA_DECLARATION)
            ->modalSubmitActionLabel('Continuer')
            ->mountUsing(fn (Form $form) => $form->fill([
                'jeton' => (string) Str::uuid(),
                'portee' => self::TOUS_LES_MAGASINS,
                'consultations' => PermissionRegistry::casesDuCabinet(PermissionRegistry::parDefautDuCabinet($type)),
                'achats' => [],
                'telechargements' => [],
            ]))
            ->form(fn (): array => [
                Hidden::make('jeton'),
                TextInput::make('siren')->label('SIREN de son cabinet')->required()->length(9)->live(onBlur: true),
                Placeholder::make('cabinet_sur_piliqi')->hiddenLabel()
                    ->content(DeclarerSonExpertComptableAction::MESSAGE_CABINET_SUR_PILIQI)
                    ->visible(fn (Get $get): bool => $this->cabinetVerifieDuSiren($get('siren')) !== null),
                TextInput::make('name')->label('Nom de la personne')->required()->maxLength(255),
                TextInput::make('email')->label('Son adresse e-mail professionnelle')->email()->required()->maxLength(255)
                    ->helperText('Le lien part à cette adresse. La personne qui le suit entre dans vos magasins avec les autorisations ci-dessous.'),
                TextInput::make('email_confirmation')->label('Confirmez cette adresse')->email()->required()->maxLength(255),
                ...self::champsDesMagasins($this->societe(), fn (): bool => true),
                CheckboxList::make('consultations')->label('Consulter')->options(array_intersect_key($libelles, array_flip(PermissionRegistry::parDefautDuCabinet($type))))->columns(2),
                CheckboxList::make('achats')->label('Achats et stock')->options(array_intersect_key($libelles, array_flip(PermissionRegistry::ajoutablesAuCabinet($type))))->columns(2)
                    ->visible(PermissionRegistry::ajoutablesAuCabinet($type) !== []),
                CheckboxList::make('telechargements')->label('Téléchargement des documents par période')->helperText(self::AIDE_TELECHARGEMENTS)
                    ->options(array_intersect_key($libelles, array_flip(PermissionRegistry::cocheesParLeProprietaire($type)))),
                Placeholder::make('jamais')->hiddenLabel()->content(self::TEXTE_JAMAIS),
                Checkbox::make('remplacer')->label(fn (): string => $this->texteDuRemplacement())->visible(fn (): bool => $this->texteDuRemplacement() !== ''),
                TextInput::make('mot_de_passe')->label('Votre mot de passe')->password()->required(),
            ])
            ->extraModalFooterActions(fn (): array => [$this->actionLuiDemanderLeLien()])
            ->registerModalActions([$this->confirmationDeLAdresse(Action::make('confirmerLaDeclaration'), self::TEXTE_DE_LA_CONFIRMATION, 'Déclarer et envoyer le lien', $visible, function (array $saisie, Action $confirmation): void {
                $this->executerSansFermer($confirmation, function () use ($saisie): void {
                    $compte = app(DeclarerSonExpertComptableAction::class)->handle(CompteConnecte::exiger(), $this->societe(), [
                        'siren' => ValeurSimple::enTexte($saisie['siren'] ?? ''),
                        'name' => $saisie['name'] ?? null,
                        'email' => $saisie['email'] ?? null,
                        'email_confirmation' => $saisie['email_confirmation'] ?? null,
                        'autorisations' => self::autorisationsCocheesDans($saisie),
                        'magasins' => self::magasinsChoisisDans($saisie),
                        'remplacer' => ($saisie['remplacer'] ?? false) === true,
                    ], ValeurSimple::enTexte($saisie['mot_de_passe'] ?? ''), ValeurSimple::enTexte($saisie['jeton'] ?? ''));
                    $this->remplir();
                    $this->annoncerLeCompte($compte, 'est déclaré');
                });
            })])
            ->action(function (array $data, Action $action): void {
                $this->executerSansFermer($action, function () use ($data): void {
                    if ($this->cabinetVerifieDuSiren($data['siren'] ?? null) !== null) {
                        throw ValidationException::withMessages(['siren' => DeclarerSonExpertComptableAction::MESSAGE_CABINET_SUR_PILIQI]);
                    }

                    $this->demanderLaConfirmationDeLAdresse(ValeursLues::champs($data), 'confirmerLaDeclaration');
                });
            });
    }

    /**
     * « Ajouter une personne de ce cabinet » à la déclaration en attente
     * (voir AjouterUnExpertComptableAction) : « Continuer » ouvre la
     * fenêtre qui répète l'adresse en gras, dont le bouton ajoute (voir
     * ConfirmeLAdresseDuLien).
     */
    public function ajouterUnePersonneAction(): Action
    {
        $visible = fn (): bool => $this->lienEnCours()?->estDeclare() === true;

        return Action::make('ajouterUnePersonne')
            ->label('Ajouter une personne de ce cabinet')
            ->icon('heroicon-o-user-plus')
            ->visible($visible)
            ->modalSubmitActionLabel('Continuer')
            ->mountUsing(fn (Form $form) => $form->fill(['jeton' => (string) Str::uuid()]))
            ->form([
                Hidden::make('jeton'),
                TextInput::make('name')->label('Nom de la personne')->required()->maxLength(255),
                TextInput::make('email')->label('Son adresse e-mail professionnelle')->email()->required()->maxLength(255)
                    ->helperText('Le lien part à cette adresse. La personne a les mêmes autorisations que les autres personnes de votre expert-comptable.'),
                TextInput::make('email_confirmation')->label('Confirmez cette adresse')->email()->required()->maxLength(255),
                TextInput::make('mot_de_passe')->label('Votre mot de passe')->password()->required(),
            ])
            ->registerModalActions([$this->confirmationDeLAdresse(Action::make('confirmerLAjout'), self::TEXTE_DE_LA_CONFIRMATION, 'Ajouter et envoyer le lien', $visible, function (array $saisie, Action $confirmation): void {
                $this->executerSansFermer($confirmation, function () use ($saisie): void {
                    $declaration = $this->lienEnCours();

                    if ($declaration === null || ! $declaration->estDeclare()) {
                        throw new LogicException(AjouterUnExpertComptableAction::MESSAGE_PLUS_DECLARE);
                    }

                    $compte = app(AjouterUnExpertComptableAction::class)->handle(CompteConnecte::exiger(), $declaration, [
                        'name' => $saisie['name'] ?? null,
                        'email' => $saisie['email'] ?? null,
                        'email_confirmation' => $saisie['email_confirmation'] ?? null,
                    ], ValeurSimple::enTexte($saisie['mot_de_passe'] ?? ''), ValeurSimple::enTexte($saisie['jeton'] ?? ''));
                    $this->annoncerLeCompte($compte, 'est ajouté');
                });
            })])
            ->action(function (array $data, Action $action): void {
                $this->executerSansFermer($action, fn () => $this->demanderLaConfirmationDeLAdresse(ValeursLues::champs($data), 'confirmerLAjout'));
            });
    }

    /**
     * Exécute le geste d'une fenêtre qui crée un compte ; un refus (une
     * donnée refusée, une règle, un droit) s'affiche et ramène à la saisie,
     * sans la fermer, pour que le propriétaire corrige sans tout
     * ressaisir ; un e-mail qui n'a pas pu partir s'affiche et ferme les
     * fenêtres, le compte étant créé.
     *
     * @param  Closure(): void  $geste
     */
    private function executerSansFermer(Action $action, Closure $geste): void
    {
        try {
            $geste();
        } catch (ValidationException $refus) {
            Notification::make()->title(implode(' ', $refus->validator->errors()->all()))->danger()->send();
            $this->revenirALaSaisie($action);
        } catch (LogicException|AuthorizationException|CourrielNonParti $refus) {
            Notification::make()->title($refus->getMessage())->danger()->send();

            if (! $refus instanceof CourrielNonParti) {
                $this->revenirALaSaisie($action);
            }

            $this->remplir();
        }
    }

    /**
     * Le texte de la case qui confirme le remplacement du lien en cours et
     * le retrait de la demande envoyée ; vide quand il n'y a rien à
     * remplacer.
     */
    private function texteDuRemplacement(): string
    {
        $enCours = $this->lienEnCours();
        $demande = $this->demandesEnvoyees()->first();
        $texte = match (true) {
            $enCours?->estDeclare() === true => 'Je remplace '.$enCours->nomDuCabinetDeclare().' : les comptes de '.implode(', ', $this->personnesDeLaDeclaration()->map(fn (User $compte): string => $compte->name)->all()).' seront fermés',
            $enCours !== null => 'Je remplace '.($this->cabinetDu($enCours)['societe']->raison_sociale ?? 'mon cabinet lié').' : son accès prend fin aussitôt',
            default => '',
        };

        if ($demande !== null) {
            $cabinet = $this->cabinetDu($demande)['societe']->raison_sociale ?? 'ce cabinet';
            $texte = $texte === '' ? "Je retire ma demande à {$cabinet}" : "{$texte}, et je retire ma demande à {$cabinet}";
        }

        return $texte;
    }

    /**
     * Annonce le compte créé au propriétaire : le lien et son échéance,
     * l'adresse où il est parti, et l'identifiant, que la personne
     * découvrira en choisissant son mot de passe.
     */
    private function annoncerLeCompte(User $compte, string $verbe): void
    {
        $attachement = app(ExpertsComptablesDeclares::class)->attachementDe($compte);
        $echeance = $attachement?->jeton_expire_le === null ? null : Calendrier::deParis()->afficher($attachement->jeton_expire_le, 'd/m/Y à H:i');

        Notification::make()
            ->success()
            ->persistent()
            ->title("{$compte->name} {$verbe}")
            ->body(($echeance === null ? "Le lien n'a pas pu partir : envoyez-lui un nouveau lien depuis sa fiche." : "Un lien valable jusqu'au {$echeance} est parti à {$compte->email}.")." Son identifiant : {$compte->identifiantDeConnexion()} ; la personne le découvrira en choisissant son mot de passe.")
            ->send();
    }

    /**
     * Les personnes non retirées de la déclaration en attente, par
     * identifiant croissant ; aucune sans déclaration.
     *
     * @return Collection<int, User>
     */
    public function personnesDeLaDeclaration(): Collection
    {
        $declaration = $this->lienEnCours();

        return $declaration?->estDeclare() === true ? app(ExpertsComptablesDeclares::class)->comptesDe($declaration) : new Collection;
    }

    /**
     * L'état du lien du mot de passe d'une personne de la déclaration, dit
     * au propriétaire.
     */
    public function etatDuLienDe(User $compte): string
    {
        $attachement = app(ExpertsComptablesDeclares::class)->attachementDe($compte);
        $calendrier = Calendrier::deParis();

        return match (true) {
            $attachement === null => '—',
            $attachement->aChoisiSonMotDePasse() => 'Mot de passe choisi le '.$calendrier->afficher(ValeurRequise::de($attachement->mot_de_passe_choisi_le, 'la date du premier mot de passe'), 'd/m/Y'),
            $attachement->lienValable() => 'Lien valable jusqu\'au '.$calendrier->afficher(ValeurRequise::de($attachement->jeton_expire_le, 'l\'échéance du lien'), 'd/m/Y à H:i'),
            default => 'Lien expiré, effacé ou non parti : à renvoyer',
        };
    }

    /**
     * L'adresse de la fiche d'une personne de la déclaration, dans la liste
     * de l'équipe.
     */
    public function urlDeLaFiche(User $compte): string
    {
        return MembreResource::getUrl('view', ['record' => $compte]);
    }

    /**
     * Le cabinet d'un lien de la société, réduit à ce que la page montre :
     * sa dénomination, son SIREN, la ville de son siège et son n°
     * d'inscription à l'Ordre.
     *
     * @return array{societe: Societe, numero_ordre: string|null}
     */
    public function cabinetDu(LienCabinet $lien): array
    {
        return [
            'societe' => Societe::query()->whereKey($lien->cabinet_id)->firstOrFail(['id', 'siren', 'raison_sociale', 'siege_code_postal', 'siege_ville']),
            'numero_ordre' => ValeursLues::texteOuNul(Cabinet::query()->withoutGlobalScope(SocieteScope::class)->where('societe_id', $lien->cabinet_id)->value('numero_ordre')),
        ];
    }

    /**
     * Les personnes du cabinet lié qui ont accès à la société : son
     * propriétaire et les collaborateurs à qui elle est confiée, par nom.
     *
     * @return list<string>
     */
    public function personnesQuiOntAcces(LienCabinet $lien): array
    {
        return ValeursLues::textes(User::query()
            ->where('societe_id', $lien->cabinet_id)
            ->where('statut', StatutCompte::Actif->value)
            ->where(fn ($comptes) => $comptes->where('role_societe', 'proprietaire')
                ->orWhereIn('id', DB::table('clients_confies')->where('societe_id', $lien->cabinet_id)->where('lien_id', $lien->getKey())->select('user_id')))
            ->orderBy('name')
            ->pluck('name'));
    }

    /**
     * Les demandes de rattachement des cabinets, encore en cours et non
     * expirées.
     *
     * @return Collection<int, LienCabinet>
     */
    public function demandesRecues(): Collection
    {
        return LienCabinet::query()->where('etat', EtatDuLien::DemandeParLeCabinet->value)->where('expire_le', '>', now())->orderByDesc('demande_le')->get();
    }

    /**
     * Les demandes envoyées par la société, encore en cours.
     *
     * @return Collection<int, LienCabinet>
     */
    public function demandesEnvoyees(): Collection
    {
        return LienCabinet::query()->where('etat', EtatDuLien::DemandeParLeClient->value)->orderByDesc('demande_le')->get();
    }

    /**
     * Les cabinets vérifiés que trouve la recherche.
     *
     * @return Collection<int, Societe>
     */
    public function resultats(): Collection
    {
        return app(RechercheDesCabinets::class)->pour($this->recherche);
    }

    /**
     * L'adresse de la page de rattachement d'une demande.
     */
    public function urlDuRattachement(LienCabinet $lien): string
    {
        return RattachementDUnCabinet::getUrl(['lien' => $lien->uuid]);
    }
}
