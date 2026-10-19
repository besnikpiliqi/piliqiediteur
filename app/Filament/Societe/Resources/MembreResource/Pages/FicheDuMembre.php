<?php

namespace App\Filament\Societe\Resources\MembreResource\Pages;

use App\Domain\Tenancy\Actions\AnnulerLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\AnnulerUneNominationAction;
use App\Domain\Tenancy\Actions\ChangerLeRoleDeSocieteAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\DemanderLaTransmissionDeLaProprieteAction;
use App\Domain\Tenancy\Actions\ModifierAdministrateurAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Actions\RedonnerLAccesAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Models\TransmissionPropriete;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Filament\Societe\Resources\MembreResource;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Group;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * La fiche d'un compte, avec les gestes que la personne connectée peut
 * faire sur lui (voir MembreResource et PorteeDesDroits) ; chaque bouton
 * n'apparaît qu'à qui peut faire ce geste, et l'action du domaine le
 * revérifie sous ses verrous sur des comptes relus. Un administrateur qui
 * n'est autorisé qu'à redonner l'accès d'un vendeur ou d'un observateur n'y
 * trouve que « Redonner l'accès » et « Afficher l'identifiant ».
 */
class FicheDuMembre extends ViewRecord
{
    protected static string $resource = MembreResource::class;

    protected function getHeaderActions(): array
    {
        $compte = fn (): User => $this->compte();

        return [
            $this->modifierSonRoleAction(),
            $this->annulerLaNominationAction(),
            $this->changerLeRoleDeSocieteAction(),
            $this->modifierLAdministrateurAction(),
            $this->transmettreLaProprieteAction(),
            $this->renvoyerLeLienDeTransmissionAction(),
            $this->annulerLaTransmissionAction(),
            $this->reintegrerAction(),
            MembreResource::actionRedonnerLAcces(Action::make('redonnerLAcces'), $compte)->after(fn () => $this->getRecord()->refresh()),
            MembreResource::actionAfficherLIdentifiant(Action::make('afficherLIdentifiant'), $compte),
            MembreResource::actionSuspendre(Action::make('suspendre'), $compte)->after(fn () => $this->getRecord()->refresh()),
            MembreResource::actionReactiver(Action::make('reactiver'), $compte)->after(fn () => $this->getRecord()->refresh()),
            MembreResource::actionRetirerDeLaSociete(Action::make('retirerDeLaSociete'), $compte)->after(fn () => $this->getRecord()->refresh()),
        ];
    }

    /**
     * Le propriétaire change le rôle et les cases d'un membre dans son
     * magasin, le seul qu'il ait (voir ModifierMembreAction).
     */
    public function modifierSonRoleAction(): Action
    {
        return Action::make('modifierSonRole')
            ->label('Rôle dans son magasin')
            ->icon('heroicon-o-adjustments-horizontal')
            ->visible(fn (): bool => $this->gereLEquipe() && $this->auteur()->estProprietaire() && $this->compte()->estMembre() && $this->compte()->affectationDeMembre() !== null)
            ->fillForm(function (): array {
                $affectation = $this->compte()->affectationDeMembre();

                return [
                    'role' => $affectation?->role?->value,
                    ...MembreResource::autorisationsAAfficher($affectation?->autorisations() ?? [], 'autorisations'),
                ];
            })
            ->form(MembreResource::champsDuRoleDeMembre())
            ->modalDescription(fn (): string => 'Son magasin ne change pas : '.($this->compte()->magasinDeSonIdentifiant()?->nom ?? '—').'.')
            ->action(function (array $data): void {
                $this->agir(function () use ($data): void {
                    $role = MembreResource::roleSaisi($data);
                    app(ModifierMembreAction::class)->handle($this->auteur(), $this->compte(), $role['role'], $role['autorisations']);
                    Notification::make()->title('Son rôle est enregistré.')->success()->send();
                });
            });
    }

    /**
     * Annule la nomination d'un responsable par un administrateur : le
     * propriétaire, ou l'administrateur qui l'a faite.
     */
    public function annulerLaNominationAction(): Action
    {
        return Action::make('annulerLaNomination')
            ->label('Annuler la nomination')
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->nominationAnnulable() !== null)
            ->modalDescription('Le rôle de responsable disparaît : la personne retrouve son rôle d\'avant, ou, si la nomination avait créé son compte, perd son accès. Aucun document n\'est touché.')
            ->action(function (): void {
                $this->agir(function (): void {
                    app(AnnulerUneNominationAction::class)->handle($this->auteur(), $this->nominationAnnulable() ?? throw new AuthorizationException(PorteeDesDroits::REFUS));
                    Notification::make()->title('La nomination est annulée.')->success()->send();
                });
            });
    }

    /**
     * Le propriétaire change le rôle de société de ce compte (membre,
     * administrateur, expert-comptable), qui garde son identifiant et son
     * historique (voir ChangerLeRoleDeSocieteAction) ; un compte qui n'a pas
     * encore de magasin et devient membre reçoit, une fois pour toutes, le
     * magasin réel ouvert choisi ici. Quand son mot de passe est encore son
     * identifiant, la fenêtre l'avertit et la personne présente peut
     * choisir le sien maintenant.
     */
    public function changerLeRoleDeSocieteAction(): Action
    {
        return Action::make('changerLeRoleDeSociete')
            ->label('Changer de rôle')
            ->icon('heroicon-o-arrows-right-left')
            ->color('warning')
            ->visible(fn (): bool => $this->auteur()->can('changerLeRoleDeSociete', $this->compte()))
            ->fillForm(fn (): array => ['jeton_acces' => (string) Str::uuid(), 'mode' => 'plus_tard', ...MembreResource::modeleDeLAdministrateur()])
            ->form([
                Select::make('role_societe')
                    ->label('Nouveau rôle')
                    ->options(fn (): array => collect([RoleSociete::Membre, RoleSociete::Administrateur, RoleSociete::ExpertComptable])
                        ->reject(fn (RoleSociete $role): bool => $role === $this->compte()->role_societe)
                        ->mapWithKeys(fn (RoleSociete $role): array => [$role->value => $role->getLabel()])
                        ->all())
                    ->required()
                    ->live(),
                Group::make([
                    Placeholder::make('magasin_de_rattachement')
                        ->label('Son magasin, définitif')
                        ->content(fn (): string => $this->compte()->magasinDeSonIdentifiant()?->nom ?? '—')
                        ->visible(fn (): bool => $this->compte()->magasinDeSonIdentifiant() !== null),
                    Select::make('magasin_id')
                        ->label('Son magasin, définitif')
                        ->helperText('Choisi une fois pour toutes, parmi les magasins réels ouverts : un compte de membre ne change jamais de magasin.')
                        ->options(fn (): array => $this->magasinsReelsOuverts())
                        ->required(fn (): bool => $this->compte()->magasinDeSonIdentifiant() === null)
                        ->visible(fn (): bool => $this->compte()->magasinDeSonIdentifiant() === null),
                    ...MembreResource::champsDuRoleDeMembre(),
                ])->visible(fn (Get $get): bool => $get('role_societe') === RoleSociete::Membre->value),
                Group::make(MembreResource::champsDeLAdministrateur())
                    ->visible(fn (Get $get): bool => $get('role_societe') === RoleSociete::Administrateur->value),
                Placeholder::make('avertissement')
                    ->hiddenLabel()
                    ->content(ListMembres::AVERTISSEMENT_IDENTIFIANT)
                    ->visible(fn (): bool => $this->compte()->mot_de_passe_est_l_identifiant),
                Hidden::make('jeton_acces'),
                Radio::make('mode')
                    ->label('La personne est-elle là ?')
                    ->options(['plus_tard' => 'Non : elle choisira son mot de passe à sa prochaine connexion, si son nouveau rôle l\'exige', 'present' => 'Oui : elle choisit son mot de passe maintenant'])
                    ->live()
                    ->visible(fn (): bool => $this->compte()->mot_de_passe_est_l_identifiant),
                ...MembreResource::champsDuMotDePasseChoisi(fn (Get $get): bool => $this->compte()->mot_de_passe_est_l_identifiant && $get('mode') === 'present'),
            ])
            ->modalDescription('Le compte garde son identifiant et son historique ; seuls son rôle et ses autorisations changent. Un administrateur ou un expert-comptable ne garde aucune affectation de membre ; ses ventes passées restent à son nom.')
            ->action(function (array $data, Form $form): void {
                $compte = $this->compte();
                $enPresence = $compte->mot_de_passe_est_l_identifiant && ($data['mode'] ?? null) === 'present';

                if ($enPresence) {
                    MembreResource::exigerLeMotDePasseChoisi((string) ($data['mot_de_passe'] ?? ''), $compte, $form);
                }

                $this->agir(function () use ($data, $compte, $enPresence): void {
                    $action = app(ChangerLeRoleDeSocieteAction::class);

                    match (RoleSociete::from((string) $data['role_societe'])) {
                        RoleSociete::Membre => $action->versMembre($this->auteur(), $compte, MembreResource::roleSaisi($data)['role'], MembreResource::roleSaisi($data)['autorisations'], filled($data['magasin_id'] ?? null) ? (int) $data['magasin_id'] : null),
                        RoleSociete::Administrateur => $action->versAdministrateur(...$this->porteeSaisie($data)),
                        RoleSociete::ExpertComptable => $action->versExpertComptable($this->auteur(), $compte),
                        RoleSociete::Proprietaire => throw new AuthorizationException(PorteeDesDroits::REFUS),
                    };

                    if ($enPresence) {
                        app(RedonnerLAccesAction::class)->handle($this->auteur(), $compte->refresh(), true, (string) $data['mot_de_passe'], (string) $data['jeton_acces']);
                    }

                    Notification::make()->title('Son rôle est changé.')->success()->send();
                });
            });
    }

    public function modifierLAdministrateurAction(): Action
    {
        return Action::make('modifierLAdministrateur')
            ->label('Modifier sa portée et ses autorisations')
            ->icon('heroicon-o-adjustments-horizontal')
            ->visible(fn (): bool => $this->auteur()->can('modifierUnAdministrateur', $this->compte()) && ! $this->compte()->estRetire())
            ->fillForm(fn (): array => $this->porteeActuelle())
            ->form(MembreResource::champsDeLAdministrateur())
            ->action(function (array $data): void {
                $this->agir(fn () => app(ModifierAdministrateurAction::class)->modifier(...$this->porteeSaisie($data)));
            });
    }

    /**
     * Le propriétaire demande la transmission de la propriété à cet
     * administrateur, en redonnant son mot de passe : un lien part à
     * l'adresse que l'administrateur a renseignée lui-même, et la propriété
     * ne passe que quand il le suit (voir
     * DemanderLaTransmissionDeLaProprieteAction).
     */
    public function transmettreLaProprieteAction(): Action
    {
        return Action::make('transmettreLaPropriete')
            ->label('Transmettre la propriété')
            ->icon('heroicon-o-key')
            ->color('danger')
            ->visible(fn (): bool => $this->auteur()->can('transmettreLaPropriete', $this->compte()) && $this->transmissionEnAttente() === null)
            ->modalDescription(fn (): string => "Un lien, valable 24 heures, part à l'adresse que {$this->compte()->name} a renseignée dans son profil : la propriété ne passera que quand il l'aura suivi, connecté à son compte, et vous pourrez annuler d'ici là. Il deviendra alors le propriétaire de la société, avec tous les droits sauf celui de vendre ; vous en deviendrez administrateur de tous les magasins, avec toutes les autorisations de magasin sauf celles de la vente, et toutes celles de la société. Sa qualité de représentant doit être prouvée à son nom, et il doit avoir choisi son propre mot de passe.")
            ->form([
                TextInput::make('mot_de_passe')->label('Votre mot de passe')->password()->autocomplete('current-password')->required(),
            ])
            ->action(function (array $data): void {
                $this->agir(function () use ($data): void {
                    app(DemanderLaTransmissionDeLaProprieteAction::class)->handle($this->auteur(), $this->compte(), $data['mot_de_passe']);
                    Notification::make()->title('Un lien a été envoyé à l\'adresse de cet administrateur : la propriété ne passera que quand il l\'aura suivi.')->success()->send();
                });
            });
    }

    public function renvoyerLeLienDeTransmissionAction(): Action
    {
        return Action::make('renvoyerLeLienDeTransmission')
            ->label('Renvoyer le lien de transmission')
            ->icon('heroicon-o-envelope')
            ->requiresConfirmation()
            ->modalDescription('Un nouveau lien, valable 24 heures, part à son adresse ; le précédent ne sert plus.')
            ->visible(fn (): bool => $this->auteur()->can('transmettreLaPropriete', $this->compte()) && $this->transmissionEnAttente() !== null)
            ->action(function (): void {
                $this->agir(function (): void {
                    app(DemanderLaTransmissionDeLaProprieteAction::class)->renvoyerLeLien($this->auteur(), $this->compte());
                    Notification::make()->title('Un nouveau lien a été envoyé à l\'adresse de cet administrateur.')->success()->send();
                });
            });
    }

    public function annulerLaTransmissionAction(): Action
    {
        return Action::make('annulerLaTransmission')
            ->label('Annuler la transmission')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription('Le lien envoyé ne sert plus ; rien n\'a changé dans la société.')
            ->visible(fn (): bool => $this->auteur()->estProprietaire() && $this->transmissionEnAttente() !== null)
            ->action(function (): void {
                $this->agir(function (): void {
                    app(AnnulerLaTransmissionDeLaProprieteAction::class)->handle($this->auteur(), $this->compte());
                    Notification::make()->title('La transmission de la propriété est annulée.')->success()->send();
                });
            });
    }

    /**
     * Réintégrer un compte retiré, dans son rôle et, pour un membre, dans
     * son magasin, comme une création : le propriétaire choisit son rôle de
     * magasin, ou la portée d'un administrateur ; un administrateur
     * autorisé réintègre un responsable d'un magasin réel de sa portée.
     */
    public function reintegrerAction(): Action
    {
        return Action::make('reintegrer')
            ->label('Réintégrer')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (): bool => $this->peutReintegrer())
            ->fillForm(fn (): array => $this->compte()->estAdministrateur() ? MembreResource::modeleDeLAdministrateur() : [])
            ->form(fn (): array => match (true) {
                $this->compte()->estAdministrateur() => MembreResource::champsDeLAdministrateur(),
                $this->compte()->estMembre() && $this->auteur()->estProprietaire() => MembreResource::champsDuRoleDeMembre(),
                default => [],
            })
            ->requiresConfirmation(fn (): bool => $this->compte()->estExpertComptable() || ! $this->auteur()->estProprietaire())
            ->modalDescription(fn (): string => $this->compte()->estMembre() ? 'Dans son magasin : '.($this->compte()->magasinDeSonIdentifiant()?->nom ?? '—').'.' : 'Il retrouve son rôle.')
            ->action(function (array $data): void {
                $this->agir(function () use ($data): void {
                    $action = app(ChangerStatutCompteAction::class);
                    $compte = $this->compte();

                    if ($compte->estAdministrateur()) {
                        $action->reintegrerLAdministrateur(...$this->porteeSaisie($data));
                    } elseif ($compte->estExpertComptable()) {
                        $action->reintegrerLExpertComptable($this->auteur(), $compte);
                    } elseif ($this->auteur()->estProprietaire()) {
                        $role = MembreResource::roleSaisi($data);
                        $action->reintegrer($this->auteur(), $compte, $role['role'], $role['autorisations']);
                    } else {
                        $action->reintegrer($this->auteur(), $compte, RoleMagasin::Responsable);
                    }
                });
            });
    }

    private function peutReintegrer(): bool
    {
        $compte = $this->compte();

        if (! $compte->estRetire()) {
            return false;
        }

        if ($compte->estAdministrateur()) {
            return $this->auteur()->can('modifierUnAdministrateur', $compte);
        }

        if ($compte->estExpertComptable()) {
            return $this->gereLEquipe() && $this->auteur()->can('creerLExpertComptable', User::class);
        }

        return collect(RoleMagasin::cases())->contains(fn (RoleMagasin $role): bool => app(PorteeDesDroits::class)->peutReintegrer($this->auteur(), $compte, $role));
    }

    private function nominationAnnulable(): ?Affectation
    {
        $affectation = $this->compte()->affectationDeMembre();

        return $affectation !== null && app(PorteeDesDroits::class)->peutAnnulerLaNomination($this->auteur(), $affectation) ? $affectation : null;
    }

    /**
     * La demande de transmission de la propriété qui attend pour ce compte.
     */
    private function transmissionEnAttente(): ?TransmissionPropriete
    {
        return TransmissionPropriete::query()
            ->where('destinataire_id', $this->compte()->getKey())
            ->whereNull('annulee_le')
            ->whereNull('acceptee_le')
            ->where('expire_le', '>', now())
            ->first();
    }

    /**
     * Les magasins réels ouverts de la société, où un compte qui n'a pas
     * encore de magasin peut devenir membre.
     *
     * @return array<int, string>
     */
    private function magasinsReelsOuverts(): array
    {
        return Magasin::query()
            ->where('societe_id', MembreResource::societe()->getKey())
            ->where('est_demonstration', false)
            ->where('statut', StatutMagasin::Actif->value)
            ->orderBy('nom')
            ->pluck('nom', 'id')
            ->all();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: User, 1: User, 2: list<int>|null, 3: list<string>, 4: list<string>}
     */
    private function porteeSaisie(array $data): array
    {
        return [
            $this->auteur(),
            $this->compte(),
            $data['portee'] === 'tous' ? null : array_map(intval(...), $data['magasins'] ?? []),
            MembreResource::autorisationsSaisies($data, 'autorisations_magasin'),
            array_values($data['autorisations_societe'] ?? []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function porteeActuelle(): array
    {
        $compte = $this->compte();
        $affectations = $compte->affectations()->whereNull('role')->get();
        $premiere = $affectations->first();

        return [
            'portee' => $compte->administreTousLesMagasins() ? 'tous' : 'selection',
            'magasins' => $affectations->pluck('magasin_id')->filter()->map(fn ($id): int => (int) $id)->values()->all(),
            'autorisations_societe' => $compte->autorisationsDeSociete(),
            ...MembreResource::autorisationsAAfficher($premiere?->autorisations() ?? [], 'autorisations_magasin'),
        ];
    }

    /**
     * Exécute le geste, affiche un refus sans casser la page, puis relit
     * le compte pour que la fiche montre son nouvel état.
     */
    private function agir(Closure $geste): void
    {
        MembreResource::executerLeGeste($geste);
        $this->getRecord()->refresh();
    }

    /**
     * La personne connectée gère-t-elle encore l'équipe de sa société (pas
     * dans une société révoquée, voir PorteeDesDroits::gereSonEquipe()) ?
     */
    private function gereLEquipe(): bool
    {
        return app(PorteeDesDroits::class)->gereSonEquipe($this->auteur());
    }

    private function compte(): User
    {
        /** @var User */
        return $this->getRecord();
    }

    private function auteur(): User
    {
        $auteur = auth()->user();

        abort_unless($auteur instanceof User, 403);

        return $auteur;
    }
}
