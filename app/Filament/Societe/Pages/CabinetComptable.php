<?php

namespace App\Filament\Societe\Pages;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\Cabinets\Actions\DesignerUnCabinetAction;
use App\Domain\Cabinets\Actions\InviterSonCabinetACreerSonEspaceAction;
use App\Domain\Cabinets\Actions\MettreFinAuLienAction;
use App\Domain\Cabinets\Actions\ReglerLesAutorisationsDuCabinetAction;
use App\Domain\Cabinets\Actions\RetirerLaDemandeDeLienAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Models\Cabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Cabinets\Services\RechercheDesCabinets;
use App\Domain\Tenancy\Enums\StatutCompte;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Support\ValeursLues;
use App\Filament\Concerns\GereLesExceptionsMetier;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

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
 *   InviterSonCabinetACreerSonEspaceAction).
 * Les lectures du cabinet sont celles que CLAUDE.md permet : jamais
 * l'identifiant ni l'adresse d'un de ses comptes. Lier un cabinet ne
 * valide jamais une société qui attend son code.
 *
 * @property Form $form
 */
class CabinetComptable extends Page implements HasForms
{
    use GereLesExceptionsMetier, InteractsWithForms;

    public const AIDE_TELECHARGEMENTS = 'Jamais accordé par défaut : vous seul le cochez. Votre cabinet n\'y reçoit que les documents que ses autres autorisations lui laissent voir.';

    public const TEXTE_JAMAIS = 'Jamais la vente, l\'encaissement, la caisse, les comptes ni les réglages de la société.';

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
     * Le formulaire des autorisations, tel que la base les garde pour le
     * lien actif, en ses deux groupes.
     */
    private function remplir(): void
    {
        $accordees = $this->lienActif()?->autorisations() ?? [];
        $type = $this->societe()->type_activite;

        $this->form->fill([
            'consultations' => array_values(array_intersect($accordees, PermissionRegistry::parDefautDuCabinet($type))),
            'achats' => array_values(array_intersect($accordees, PermissionRegistry::ajoutablesAuCabinet($type))),
            'telechargements' => array_values(array_intersect($accordees, PermissionRegistry::cocheesParLeProprietaire($type))),
        ]);
    }

    /**
     * Les autorisations cochées, des deux groupes.
     *
     * @return list<string>
     */
    private function autorisationsCochees(): array
    {
        $etat = $this->form->getState();

        return array_values(array_unique([...($etat['consultations'] ?? []), ...($etat['achats'] ?? []), ...($etat['telechargements'] ?? [])]));
    }

    public function societe(): Societe
    {
        /** @var Societe */
        return Filament::getTenant();
    }

    public function lienActif(): ?LienCabinet
    {
        return LienCabinet::query()->where('etat', EtatDuLien::Actif->value)->first();
    }

    public function form(Form $form): Form
    {
        $type = $this->societe()->type_activite;
        $libelles = PermissionRegistry::libellesDuCabinet($type);

        return $form
            ->schema([
                CheckboxList::make('consultations')
                    ->label('Consulter')
                    ->options(array_intersect_key($libelles, array_flip(PermissionRegistry::parDefautDuCabinet($type))))
                    ->columns(2)
                    ->hidden(fn (): bool => $this->lienActif() === null),
                CheckboxList::make('achats')
                    ->label('Achats et stock')
                    ->options(array_intersect_key($libelles, array_flip(PermissionRegistry::ajoutablesAuCabinet($type))))
                    ->columns(2)
                    ->hidden(fn (): bool => $this->lienActif() === null || PermissionRegistry::ajoutablesAuCabinet($type) === []),
                CheckboxList::make('telechargements')
                    ->label('Téléchargement des documents par période')
                    ->helperText(self::AIDE_TELECHARGEMENTS)
                    ->options(array_intersect_key($libelles, array_flip(PermissionRegistry::cocheesParLeProprietaire($type))))
                    ->hidden(fn (): bool => $this->lienActif() === null),
                Placeholder::make('jamais')->hiddenLabel()->content(self::TEXTE_JAMAIS)->hidden(fn (): bool => $this->lienActif() === null),
            ])
            ->statePath('data');
    }

    public function enregistrerLesAutorisationsAction(): Action
    {
        return Action::make('enregistrerLesAutorisations')
            ->label('Enregistrer')
            ->requiresConfirmation(fn (): bool => $this->autorisationsCochees() === [])
            ->modalDescription('Sans aucune autorisation, votre cabinet reste lié et voit votre identité, vos réglages fiscaux et vos magasins, mais n\'ouvre plus aucun document, aucune fiche ni aucun téléchargement.')
            ->visible(fn (): bool => $this->lienActif() !== null)
            ->action(function (): void {
                static::executerSansCasser(function (): void {
                    $lien = $this->lienActif() ?? throw new LogicException('Votre société n\'a plus de cabinet lié.');
                    app(ReglerLesAutorisationsDuCabinetAction::class)->handle(auth()->user(), $lien, $this->autorisationsCochees());
                    Notification::make()->title('Les autorisations de votre cabinet sont enregistrées.')->success()->send();
                });
            });
    }

    public function mettreFinAction(): Action
    {
        return Action::make('mettreFin')
            ->label('Mettre fin au lien')
            ->color('danger')
            ->icon('heroicon-o-link-slash')
            ->requiresConfirmation()
            ->modalDescription('L\'accès de votre cabinet à votre société s\'arrête aussitôt ; il en est prévenu.')
            ->visible(fn (): bool => $this->lienActif() !== null)
            ->action(function (): void {
                static::executerSansCasser(function (): void {
                    $lien = $this->lienActif() ?? throw new LogicException('Votre société n\'a plus de cabinet lié.');
                    app(MettreFinAuLienAction::class)->handle(auth()->user(), $lien);
                    $this->remplir();
                    Notification::make()->title('Le lien avec votre cabinet a pris fin.')->success()->send();
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
                    $lien = LienCabinet::query()->parUuid((string) ($arguments['uuid'] ?? ''))->firstOrFail();
                    app(RetirerLaDemandeDeLienAction::class)->handle(auth()->user(), $lien);
                    Notification::make()->title('Votre demande est retirée.')->success()->send();
                });
            });
    }

    public function demanderLeLienAction(): Action
    {
        return Action::make('demanderLeLien')
            ->label('Demander le lien')
            ->requiresConfirmation()
            ->modalDescription('Le propriétaire de ce cabinet reçoit votre demande et y répond dans les 7 jours ; tant qu\'il ne l\'a pas acceptée, il n\'a aucun accès à votre société.')
            ->action(function (array $arguments): void {
                static::executerSansCasser(function () use ($arguments): void {
                    $cabinet = app(RechercheDesCabinets::class)->pour($this->recherche)->firstWhere('uuid', (string) ($arguments['uuid'] ?? ''))
                        ?? throw new LogicException(DesignerUnCabinetAction::MESSAGE_CABINET_NON_LIABLE);
                    app(DesignerUnCabinetAction::class)->handle(auth()->user(), $this->societe(), Societe::query()->whereKey($cabinet->getKey())->firstOrFail());
                    Notification::make()->title('Votre demande est envoyée au cabinet.')->success()->send();
                });
            });
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
                    app(InviterSonCabinetACreerSonEspaceAction::class)->handle(auth()->user(), $this->societe(), (string) $data['email']);
                    Notification::make()->title('L\'invitation est envoyée.')->success()->send();
                });
            });
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
            'numero_ordre' => Cabinet::query()->withoutGlobalScope(SocieteScope::class)->where('societe_id', $lien->cabinet_id)->value('numero_ordre'),
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
