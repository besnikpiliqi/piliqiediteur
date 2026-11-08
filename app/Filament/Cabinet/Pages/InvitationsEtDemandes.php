<?php

namespace App\Filament\Cabinet\Pages;

use App\Domain\Cabinets\Actions\AccepterLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\InviterUneEntrepriseAction;
use App\Domain\Cabinets\Actions\RefuserLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\RetirerLaDemandeDeLienAction;
use App\Domain\Cabinets\Actions\RetirerLInvitationAction;
use App\Domain\Cabinets\Enums\EtatDuLien;
use App\Domain\Cabinets\Enums\OrigineDuLien;
use App\Domain\Cabinets\Exceptions\CabinetNonVerifieException;
use App\Domain\Cabinets\Models\InvitationCabinet;
use App\Domain\Cabinets\Models\LienCabinet;
use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Models\Societe;
use App\Filament\Concerns\GereLesExceptionsMetier;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as Liste;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Les invitations et les demandes de lien d'un cabinet d'expertise
 * comptable, pour son propriétaire seul (voir InvitationCabinetPolicy) : un
 * collaborateur n'y entre pas, elles nomment des sociétés qui ne lui sont
 * pas confiées. En tête, « Inviter un client », par son seul SIREN, avec
 * l'adresse e-mail à laquelle part le lien et le mot de passe redemandé
 * (voir InviterUneEntrepriseAction), désactivé tant que le cabinet n'est
 * pas vérifié ; puis ses invitations (SIREN libres), qu'il retire ; les
 * demandes de rattachement qu'il a envoyées (SIREN déjà inscrits), dont il
 * ne voit que le SIREN saisi, avec leur état, qu'il retire tant qu'elles
 * sont en cours ; les demandes reçues des
 * clients qui l'ont désigné (dénomination, SIREN, ville), qu'il accepte ou
 * refuse. Chaque liste se lit dans le cabinet (ses invitations par leur
 * SocieteScope, ses liens par LienCabinet::duCabinet()) ; chaque geste
 * relit sa cible dans ces mêmes listes par son uuid, et l'action la
 * contrôle de nouveau.
 */
class InvitationsEtDemandes extends Page
{
    use GereLesExceptionsMetier;

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationLabel = 'Invitations et demandes';

    protected static ?string $title = 'Invitations et demandes';

    protected static ?int $navigationSort = 2;

    protected static string $view = 'filament.cabinet.pages.invitations-et-demandes';

    public static function canAccess(): bool
    {
        $cabinet = Filament::getTenant();

        return $cabinet instanceof Societe && $cabinet->estUnCabinet() && (auth()->user()?->can('viewAny', InvitationCabinet::class) ?? false);
    }

    public function cabinet(): Societe
    {
        /** @var Societe */
        return Filament::getTenant();
    }

    protected function getHeaderActions(): array
    {
        return [$this->inviterAction()];
    }

    public function inviterAction(): Action
    {
        return Action::make('inviter')
            ->label('Inviter un client')
            ->icon('heroicon-o-paper-airplane')
            ->disabled(fn (): bool => ! $this->cabinet()->estUnCabinetVerifie())
            ->tooltip(fn (): ?string => $this->cabinet()->estUnCabinetVerifie() ? null : CabinetNonVerifieException::MESSAGE)
            ->modalDescription('Un SIREN libre reçoit une invitation à créer son espace, valable 7 jours ; un SIREN déjà inscrit, une demande de rattachement, que son propriétaire voit aussi dans son espace. Dans les deux cas, l\'e-mail part à l\'adresse que vous saisissez et nomme votre cabinet.')
            ->form([
                TextInput::make('siren')->label('SIREN du client')->required()->length(9)->regex('/^\d{9}$/'),
                TextInput::make('email')->label('Adresse e-mail à laquelle part le lien')->email()->required()->maxLength(255),
                TextInput::make('mot_de_passe')->label('Votre mot de passe')->password()->autocomplete('current-password')->required(),
            ])
            ->action(function (array $data): void {
                static::executerSansCasser(function () use ($data): void {
                    try {
                        $resultat = app(InviterUneEntrepriseAction::class)->handle(auth()->user(), $this->cabinet(), (string) $data['siren'], (string) $data['email'], (string) $data['mot_de_passe']);
                    } catch (ValidationException $refus) {
                        throw new LogicException((string) collect($refus->errors())->flatten()->first());
                    }

                    Notification::make()->title($resultat->message((string) $data['email']))->success()->send();
                });
            });
    }

    public function retirerLInvitationAction(): Action
    {
        return Action::make('retirerLInvitation')
            ->label('Retirer')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Le lien envoyé ne sert plus.')
            ->action(function (array $arguments): void {
                static::executerSansCasser(function () use ($arguments): void {
                    $invitation = InvitationCabinet::query()->parUuid((string) ($arguments['uuid'] ?? ''))->firstOrFail();
                    app(RetirerLInvitationAction::class)->handle(auth()->user(), $invitation);
                    Notification::make()->title('L\'invitation est retirée.')->success()->send();
                });
            });
    }

    public function retirerLaDemandeAction(): Action
    {
        return Action::make('retirerLaDemande')
            ->label('Retirer')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Le lien de l\'e-mail ne sert plus.')
            ->action(function (array $arguments): void {
                static::executerSansCasser(function () use ($arguments): void {
                    app(RetirerLaDemandeDeLienAction::class)->handle(auth()->user(), $this->demande((string) ($arguments['uuid'] ?? '')));
                    Notification::make()->title('La demande est retirée.')->success()->send();
                });
            });
    }

    public function accepterLaDemandeAction(): Action
    {
        return Action::make('accepterLaDemande')
            ->label('Accepter')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription('Ce client devient votre client, avec les autorisations de consultation par défaut, qu\'il réglera ensuite. Vous le confierez à vos collaborateurs.')
            ->action(function (array $arguments): void {
                static::executerSansCasser(function () use ($arguments): void {
                    app(AccepterLaDemandeDeLienAction::class)->handle(auth()->user(), $this->demande((string) ($arguments['uuid'] ?? '')));
                    Notification::make()->title('Le lien est actif.')->success()->send();
                });
            });
    }

    public function refuserLaDemandeAction(): Action
    {
        return Action::make('refuserLaDemande')
            ->label('Refuser')
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (array $arguments): void {
                static::executerSansCasser(function () use ($arguments): void {
                    app(RefuserLaDemandeDeLienAction::class)->handle(auth()->user(), $this->demande((string) ($arguments['uuid'] ?? '')));
                    Notification::make()->title('La demande est refusée.')->success()->send();
                });
            });
    }

    /**
     * Les invitations du cabinet, les plus récentes d'abord.
     *
     * @return Collection<int, InvitationCabinet>
     */
    public function invitations(): Collection
    {
        return InvitationCabinet::query()->orderByDesc('created_at')->orderByDesc('id')
            ->get(['id', 'uuid', 'siren', 'email', 'etat', 'expire_le', 'invitee_par_nom', 'created_at']);
    }

    /**
     * Les demandes de rattachement envoyées par le cabinet : celles en
     * cours, et celles refusées, retirées ou expirées depuis moins de 7
     * jours, qui empêchent encore une nouvelle demande au même SIREN (voir
     * InviterUneEntrepriseAction), chacune avec le seul SIREN que le
     * cabinet a saisi, son état et la date de sa fin ; une demande dont
     * l'échéance est passée se lit expirée, avant même que la tâche
     * horaire ne l'expire.
     *
     * @return Liste<int, array{uuid: string, siren: string, demande_le: CarbonImmutable|null, expire_le: CarbonImmutable|null, en_cours: bool, etat: string, fin: CarbonImmutable|null}>
     */
    public function demandesEnvoyees(): Liste
    {
        $depuis = Calendrier::deParis()->decaler(now(), CarbonInterval::days(-InviterUneEntrepriseAction::DELAI_AVANT_UNE_NOUVELLE_INVITATION_EN_JOURS));

        return LienCabinet::query()->duCabinet($this->cabinet())
            ->where('origine', OrigineDuLien::DemandeDuCabinet->value)
            ->where(fn ($demandes) => $demandes->where('etat', EtatDuLien::DemandeParLeCabinet->value)->orWhere(fn ($closes) => $closes->closeApres($depuis)))
            ->orderByDesc('demande_le')
            ->orderByDesc('id')
            ->get(['id', 'uuid', 'societe_id', 'etat', 'demande_le', 'expire_le', 'repondu_le'])
            ->map(fn (LienCabinet $lien): array => [
                'uuid' => (string) $lien->uuid,
                'siren' => (string) Societe::query()->whereKey($lien->societe_id)->value('siren'),
                'demande_le' => $lien->demande_le,
                'expire_le' => $lien->expire_le,
                'en_cours' => $lien->estUneDemandeValable(),
                'etat' => $lien->estUneDemandeValable() ? 'En cours' : ($lien->estUneDemande() ? EtatDuLien::Expire : $lien->etat)->getLabel(),
                'fin' => $lien->estUneDemandeValable() ? null : ($lien->estUneDemande() ? $lien->expire_le : $lien->finDeLaDemande()),
            ]);
    }

    /**
     * Les demandes reçues des clients qui ont désigné le cabinet, encore en
     * cours, chacune avec la dénomination, le SIREN et la ville du client.
     *
     * @return Liste<int, array{uuid: string, client: Societe|null, demande_le: CarbonImmutable|null, expire_le: CarbonImmutable|null}>
     */
    public function demandesRecues(): Liste
    {
        $demandes = LienCabinet::query()->duCabinet($this->cabinet())
            ->where('etat', EtatDuLien::DemandeParLeClient->value)
            ->orderByDesc('demande_le')
            ->get(['id', 'uuid', 'societe_id', 'demande_le', 'expire_le']);
        $clients = Societe::query()->whereKey($demandes->pluck('societe_id')->all())->get(['id', 'siren', 'raison_sociale', 'siege_ville'])->keyBy('id');

        return $demandes->map(fn (LienCabinet $lien): array => [
            'uuid' => (string) $lien->uuid,
            'client' => $clients[$lien->societe_id],
            'demande_le' => $lien->demande_le,
            'expire_le' => $lien->expire_le,
        ]);
    }

    /**
     * Une demande du cabinet, par son uuid.
     */
    private function demande(string $uuid): LienCabinet
    {
        return LienCabinet::query()->duCabinet($this->cabinet())->parUuid($uuid)->firstOrFail();
    }
}
