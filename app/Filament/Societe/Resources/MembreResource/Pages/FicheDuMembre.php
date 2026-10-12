<?php

namespace App\Filament\Societe\Resources\MembreResource\Pages;

use App\Domain\Tenancy\Actions\AnnulerUneNominationAction;
use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\ModifierAdministrateurAction;
use App\Domain\Tenancy\Actions\ModifierMembreAction;
use App\Domain\Tenancy\Actions\NommerAdministrateurAction;
use App\Domain\Tenancy\Actions\TransfererProprieteAction;
use App\Domain\Tenancy\Enums\RoleMagasin;
use App\Domain\Tenancy\Models\Affectation;
use App\Domain\Tenancy\Services\PorteeDesDroits;
use App\Filament\Societe\Resources\MembreResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * La fiche d'un compte, avec les gestes que la personne connectée peut
 * faire sur lui (voir MembreResource et PorteeDesDroits) ; chaque bouton
 * n'apparaît qu'à qui peut faire ce geste, et l'action du domaine le
 * revérifie sous ses verrous sur des comptes relus.
 */
class FicheDuMembre extends ViewRecord
{
    protected static string $resource = MembreResource::class;

    protected function getHeaderActions(): array
    {
        $compte = fn (): User => $this->compte();

        return [
            $this->modifierLesMagasinsAction(),
            $this->retirerUnMagasinDeResponsableAction(),
            $this->deplacerLeResponsableAction(),
            $this->annulerLaNominationAction(),
            $this->nommerAdministrateurAction(),
            $this->modifierLAdministrateurAction(),
            $this->retrograderAction(),
            $this->transmettreLaProprieteAction(),
            $this->reintegrerAction(),
            $this->reintegrerCommeExpertComptableAction(),
            MembreResource::actionSuspendre(Action::make('suspendre'), $compte)->after(fn () => $this->getRecord()->refresh()),
            MembreResource::actionReactiver(Action::make('reactiver'), $compte)->after(fn () => $this->getRecord()->refresh()),
            MembreResource::actionRetirerDeLaSociete(Action::make('retirerDeLaSociete'), $compte)->after(fn () => $this->getRecord()->refresh()),
            MembreResource::actionRenvoyerUnLien(Action::make('renvoyerUnLien'), $compte),
            MembreResource::actionRedonnerUnProvisoire(Action::make('redonnerUnProvisoire'), $compte),
        ];
    }

    /**
     * Le propriétaire choisit tous les magasins d'un membre, avec son rôle
     * et ses cases dans chacun : un magasin qu'il ne garde pas lui est
     * retiré, et sans aucun magasin il serait retiré de la société.
     */
    public function modifierLesMagasinsAction(): Action
    {
        return Action::make('modifierLesMagasins')
            ->label('Modifier ses magasins')
            ->icon('heroicon-o-building-storefront')
            ->visible(fn (): bool => $this->auteur()->estProprietaire() && $this->compte()->estMembre() && ! $this->compte()->estRetire())
            ->fillForm(fn (): array => ['affectations' => $this->compte()->affectationsDeMembre()->map(fn (Affectation $affectation): array => [
                'magasin_id' => (int) $affectation->magasin_id,
                'role' => $affectation->role->value,
                'autorisations' => $affectation->autorisations(),
            ])->values()->all()])
            ->form([MembreResource::champAffectations()])
            ->action(function (array $data): void {
                $this->agir(function () use ($data): void {
                    $voulues = MembreResource::affectationsSaisies($data['affectations']);
                    $retirees = array_fill_keys(array_diff($this->compte()->affectationsDeMembre()->pluck('magasin_id')->map(fn ($id): int => (int) $id)->all(), array_keys($voulues)), null);
                    app(ModifierMembreAction::class)->handle($this->auteur(), $this->compte(), $voulues + $retirees);
                    Notification::make()->title('Ses magasins sont enregistrés.')->success()->send();
                });
            });
    }

    /**
     * Un administrateur autorisé retire à un responsable un magasin de sa
     * portée (de la société, si c'était son dernier).
     */
    public function retirerUnMagasinDeResponsableAction(): Action
    {
        return Action::make('retirerUnMagasinDeResponsable')
            ->label('Retirer un magasin')
            ->icon('heroicon-o-minus-circle')
            ->color('danger')
            ->visible(fn (): bool => ! $this->auteur()->estProprietaire() && $this->magasinsDeResponsableRetirables() !== [])
            ->form([
                Select::make('magasin_id')->label('Magasin')->options(fn (): array => $this->magasinsDeResponsableRetirables())->required(),
            ])
            ->modalDescription('Retirer son dernier magasin retire cette personne de la société.')
            ->action(function (array $data): void {
                $this->agir(fn () => app(ModifierMembreAction::class)->handle($this->auteur(), $this->compte(), [(int) $data['magasin_id'] => null]));
            });
    }

    /**
     * Un administrateur autorisé déplace un responsable d'un magasin de sa
     * portée vers un autre.
     */
    public function deplacerLeResponsableAction(): Action
    {
        return Action::make('deplacerLeResponsable')
            ->label('Changer de magasin')
            ->icon('heroicon-o-arrows-right-left')
            ->visible(fn (): bool => ! $this->auteur()->estProprietaire() && $this->magasinsDeResponsableRetirables() !== [])
            ->form([
                Select::make('depuis')->label('Responsable de')->options(fn (): array => $this->magasinsDeResponsableRetirables())->required(),
                Select::make('vers')->label('Désormais responsable de')->options(fn (): array => MembreResource::magasinsDeLaPortee())->required()->different('depuis'),
            ])
            ->action(function (array $data): void {
                $this->agir(fn () => app(ModifierMembreAction::class)->handle($this->auteur(), $this->compte(), [
                    (int) $data['depuis'] => null,
                    (int) $data['vers'] => ['role' => RoleMagasin::Responsable],
                ]));
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
            ->visible(fn (): bool => $this->nominationsAnnulables() !== [])
            ->form([
                Select::make('affectation')->label('Nomination')->options(fn (): array => $this->nominationsAnnulables())->required(),
            ])
            ->modalDescription('Le rôle de responsable disparaît de ce magasin : la personne retrouve son rôle d\'avant, ou, si la nomination avait créé son accès, le perd. Aucun document n\'est touché.')
            ->action(function (array $data): void {
                $this->agir(function () use ($data): void {
                    app(AnnulerUneNominationAction::class)->handle($this->auteur(), Affectation::query()->where('user_id', $this->compte()->getKey())->findOrFail($data['affectation']));
                    Notification::make()->title('La nomination est annulée.')->success()->send();
                });
            });
    }

    public function nommerAdministrateurAction(): Action
    {
        return Action::make('nommerAdministrateur')
            ->label('Nommer administrateur')
            ->icon('heroicon-o-shield-check')
            ->visible(fn (): bool => $this->auteur()->can('nommerAdministrateur', $this->compte()) && ! $this->compte()->is($this->auteur()))
            ->form(MembreResource::champsDeLAdministrateur())
            ->modalDescription('Ses magasins de membre sont remplacés par sa portée d\'administrateur.')
            ->action(function (array $data): void {
                $this->agir(fn () => app(NommerAdministrateurAction::class)->handle(...$this->porteeSaisie($data)));
            });
    }

    public function modifierLAdministrateurAction(): Action
    {
        return Action::make('modifierLAdministrateur')
            ->label('Modifier sa portée et ses autorisations')
            ->icon('heroicon-o-adjustments-horizontal')
            ->visible(fn (): bool => $this->auteur()->can('modifierUnAdministrateur', $this->compte()))
            ->fillForm(fn (): array => $this->porteeActuelle())
            ->form(MembreResource::champsDeLAdministrateur())
            ->action(function (array $data): void {
                $this->agir(fn () => app(ModifierAdministrateurAction::class)->modifier(...$this->porteeSaisie($data)));
            });
    }

    public function retrograderAction(): Action
    {
        return Action::make('retrograder')
            ->label('Rétrograder en membre')
            ->icon('heroicon-o-arrow-down-circle')
            ->color('warning')
            ->visible(fn (): bool => $this->auteur()->can('modifierUnAdministrateur', $this->compte()))
            ->form([MembreResource::champAffectations()])
            ->modalDescription('Sa portée et ses autorisations de société disparaissent : choisissez ses magasins de membre.')
            ->action(function (array $data): void {
                $this->agir(fn () => app(ModifierAdministrateurAction::class)->retrograder($this->auteur(), $this->compte(), MembreResource::affectationsSaisies($data['affectations'])));
            });
    }

    /**
     * Le propriétaire transmet la propriété de la société à cet
     * administrateur actif, en redonnant son mot de passe ; l'action exige
     * que la qualité de représentant de l'administrateur soit prouvée à son
     * nom. L'ancien propriétaire, devenu administrateur de tous les
     * magasins, revient à la liste des comptes, qui ne lui montre plus que
     * ce que voit un administrateur.
     */
    public function transmettreLaProprieteAction(): Action
    {
        return Action::make('transmettreLaPropriete')
            ->label('Transmettre la propriété')
            ->icon('heroicon-o-key')
            ->color('danger')
            ->visible(fn (): bool => $this->auteur()->estProprietaire() && $this->compte()->estAdministrateur() && $this->compte()->peutAgir())
            ->modalDescription(fn (): string => "{$this->compte()->name} deviendra le propriétaire de la société, avec tous les droits ; vous en deviendrez administrateur de tous les magasins, avec toutes les autorisations. Sa qualité de représentant légal ou de mandataire doit être prouvée à son nom.")
            ->form([
                TextInput::make('mot_de_passe')->label('Votre mot de passe')->password()->autocomplete('current-password')->required(),
            ])
            ->action(function (array $data): void {
                $transmise = false;

                MembreResource::executerLeGeste(function () use ($data, &$transmise): void {
                    app(TransfererProprieteAction::class)->handle($this->auteur(), $this->compte(), $data['mot_de_passe']);
                    $transmise = true;
                });

                if ($transmise) {
                    Notification::make()->title("La propriété de la société est transmise à {$this->compte()->name}.")->success()->send();
                    $this->redirect(MembreResource::getUrl('index'));
                }
            });
    }

    /**
     * Réintégrer un compte retiré par la société, comme une création : le
     * propriétaire choisit ses magasins ; un administrateur autorisé, un
     * magasin de sa portée où il sera responsable.
     */
    public function reintegrerAction(): Action
    {
        return Action::make('reintegrer')
            ->label('Réintégrer')
            ->icon('heroicon-o-arrow-path')
            ->visible(fn (): bool => $this->compte()->estRetire() && $this->compte()->estMembre() && (int) $this->compte()->statut_change_par !== (int) $this->compte()->getKey())
            ->form(fn (): array => $this->auteur()->estProprietaire()
                ? [MembreResource::champAffectations()]
                : [Select::make('magasin_id')->label('Responsable de')->options(fn (): array => MembreResource::magasinsDeLaPortee())->required()])
            ->action(function (array $data): void {
                $this->agir(fn () => app(ChangerStatutCompteAction::class)->reintegrer(
                    $this->auteur(),
                    $this->compte(),
                    isset($data['affectations']) ? MembreResource::affectationsSaisies($data['affectations']) : [(int) $data['magasin_id'] => ['role' => RoleMagasin::Responsable]],
                ));
            });
    }

    public function reintegrerCommeExpertComptableAction(): Action
    {
        return Action::make('reintegrerCommeExpertComptable')
            ->label('Réintégrer comme expert-comptable')
            ->icon('heroicon-o-calculator')
            ->requiresConfirmation()
            ->visible(fn (): bool => $this->auteur()->estProprietaire() && $this->compte()->estRetire() && (int) $this->compte()->statut_change_par !== (int) $this->compte()->getKey())
            ->action(function (): void {
                $this->agir(fn () => app(ChangerStatutCompteAction::class)->reintegrerCommeExpertComptable($this->auteur(), $this->compte()));
            });
    }

    /**
     * Les magasins de sa portée où ce compte est responsable, qu'un
     * administrateur autorisé peut lui retirer.
     *
     * @return array<int, string>
     */
    private function magasinsDeResponsableRetirables(): array
    {
        return $this->compte()->affectationsDeMembre()
            ->filter(fn (Affectation $affectation): bool => app(PorteeDesDroits::class)->peutRetirerLAffectation($this->auteur(), $affectation))
            ->mapWithKeys(fn (Affectation $affectation): array => [(int) $affectation->magasin_id => $affectation->magasin->nom])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function nominationsAnnulables(): array
    {
        return $this->compte()->affectationsDeMembre()
            ->filter(fn (Affectation $affectation): bool => app(PorteeDesDroits::class)->peutAnnulerLaNomination($this->auteur(), $affectation))
            ->mapWithKeys(fn (Affectation $affectation): array => [(int) $affectation->getKey() => "Responsable de {$affectation->magasin->nom}"])
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
            array_values($data['autorisations_magasin'] ?? []),
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
            'autorisations_magasin' => $premiere?->autorisations() ?? [],
        ];
    }

    /**
     * Exécute le geste, affiche un refus sans casser la page, puis relit
     * le compte pour que la fiche montre son nouvel état.
     */
    private function agir(\Closure $geste): void
    {
        MembreResource::executerLeGeste($geste);
        $this->getRecord()->refresh();
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
