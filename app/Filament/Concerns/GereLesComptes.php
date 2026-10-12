<?php

namespace App\Filament\Concerns;

use App\Domain\Tenancy\Actions\ChangerStatutCompteAction;
use App\Domain\Tenancy\Actions\DonnerUnMotDePasseProvisoireAction;
use App\Domain\Tenancy\Actions\EnvoyerUnLienDeReinitialisationAction;
use App\Domain\Tenancy\Services\CreationDesComptes;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Actions\Action as ActionDeChamp;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Set;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\Action as ActionDeTable;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Les gestes sur tout un compte, partagés par l'équipe d'un magasin (voir
 * EquipeDuMagasinResource) et l'espace d'administration de la société
 * (voir MembreResource) : suspendre, réactiver, retirer de la société,
 * renvoyer un lien pour renouveler l'accès, redonner un mot de passe
 * provisoire. Chaque bouton n'apparaît qu'à qui peut faire ce geste pour ce
 * compte (voir MembrePolicy), et l'action du domaine le revérifie sous ses
 * verrous sur des comptes relus ; un refus s'affiche sans casser la page
 * (voir GereLesExceptionsMetier).
 */
trait GereLesComptes
{
    use GereLesExceptionsMetier;

    /**
     * Le mot de passe provisoire d'un nouveau compte, remis de la main à
     * la main : « Générer » en propose un de 16 caractères, affiché une
     * seule fois ; il n'est jamais envoyé par e-mail ni journalisé.
     */
    public static function champMotDePasseProvisoire(string $nom = 'mot_de_passe_provisoire'): TextInput
    {
        return TextInput::make($nom)
            ->label('Mot de passe provisoire')
            ->required()
            ->minLength(CreationDesComptes::LONGUEUR_MINIMALE_DU_PROVISOIRE)
            ->validationMessages(['min' => CreationDesComptes::MESSAGE_PROVISOIRE_TROP_COURT])
            ->helperText('À remettre vous-même, de la main à la main : aucun e-mail ne le contient. Valable 72 heures ; la personne le change à sa première connexion.')
            ->suffixAction(
                ActionDeChamp::make('generer')
                    ->label('Générer')
                    ->icon('heroicon-o-sparkles')
                    ->action(fn (Set $set) => $set($nom, Str::password(16, symbols: false))),
            )
            ->dehydrated();
    }

    /**
     * @template TAction of Action|ActionDeTable
     *
     * @param  TAction  $action
     * @param  Closure(mixed): User  $compte
     * @return TAction
     */
    public static function actionSuspendre(Action|ActionDeTable $action, Closure $compte): Action|ActionDeTable
    {
        return $action
            ->label('Suspendre')
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription('La personne n\'ouvre plus aucun magasin dès sa prochaine page, et ses sessions se ferment. Elle garde ses magasins pour sa réactivation.')
            ->visible(fn (mixed $record = null): bool => self::peut('suspendreOuReactiver', $compte($record)) && $compte($record)->estActif())
            ->action(fn (mixed $record = null) => static::executerLeGeste(fn () => app(ChangerStatutCompteAction::class)->suspendre(self::auteur(), $compte($record))));
    }

    /**
     * @template TAction of Action|ActionDeTable
     *
     * @param  TAction  $action
     * @param  Closure(mixed): User  $compte
     * @return TAction
     */
    public static function actionReactiver(Action|ActionDeTable $action, Closure $compte): Action|ActionDeTable
    {
        return $action
            ->label('Réactiver')
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->requiresConfirmation()
            ->visible(fn (mixed $record = null): bool => self::peut('suspendreOuReactiver', $compte($record)) && $compte($record)->estSuspendu())
            ->action(fn (mixed $record = null) => static::executerLeGeste(fn () => app(ChangerStatutCompteAction::class)->reactiver(self::auteur(), $compte($record))));
    }

    /**
     * @template TAction of Action|ActionDeTable
     *
     * @param  TAction  $action
     * @param  Closure(mixed): User  $compte
     * @return TAction
     */
    public static function actionRetirerDeLaSociete(Action|ActionDeTable $action, Closure $compte): Action|ActionDeTable
    {
        return $action
            ->label('Retirer de la société')
            ->icon('heroicon-o-user-minus')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(fn (mixed $record = null): string => "{$compte($record)->name} perd aussitôt tout accès à la société et à ses magasins. Son compte et son adresse restent : la société pourra le réintégrer.")
            ->visible(fn (mixed $record = null): bool => self::peut('retirerDeLaSociete', $compte($record)))
            ->action(fn (mixed $record = null) => static::executerLeGeste(fn () => app(ChangerStatutCompteAction::class)->retirer(self::auteur(), $compte($record))));
    }

    /**
     * @template TAction of Action|ActionDeTable
     *
     * @param  TAction  $action
     * @param  Closure(mixed): User  $compte
     * @return TAction
     */
    public static function actionRenvoyerUnLien(Action|ActionDeTable $action, Closure $compte): Action|ActionDeTable
    {
        return $action
            ->label('Renvoyer un lien d\'accès')
            ->icon('heroicon-o-envelope')
            ->requiresConfirmation()
            ->modalDescription('La personne reçoit à son adresse un lien pour choisir elle-même son mot de passe : vous ne le connaîtrez pas.')
            ->visible(fn (mixed $record = null): bool => self::peut('renouvelerLAcces', $compte($record)))
            ->action(fn (mixed $record = null) => static::executerLeGeste(function () use ($compte, $record): void {
                app(EnvoyerUnLienDeReinitialisationAction::class)->handle(self::auteur(), $compte($record));
                Notification::make()->title('Le lien est parti.')->success()->send();
            }));
    }

    /**
     * @template TAction of Action|ActionDeTable
     *
     * @param  TAction  $action
     * @param  Closure(mixed): User  $compte
     * @return TAction
     */
    public static function actionRedonnerUnProvisoire(Action|ActionDeTable $action, Closure $compte): Action|ActionDeTable
    {
        return $action
            ->label('Nouveau mot de passe provisoire')
            ->icon('heroicon-o-key')
            ->color('warning')
            ->modalDescription('Seulement pour une personne qui n\'a pas encore choisi son mot de passe.')
            ->form([self::champMotDePasseProvisoire()])
            ->visible(fn (mixed $record = null): bool => self::peut('donnerUnProvisoire', $compte($record)))
            ->action(fn (array $data, mixed $record = null) => static::executerLeGeste(function () use ($compte, $record, $data): void {
                app(DonnerUnMotDePasseProvisoireAction::class)->handle(self::auteur(), $compte($record), $data['mot_de_passe_provisoire']);
                Notification::make()->title('Le nouveau mot de passe provisoire est enregistré : remettez-le à la personne.')->success()->send();
            }));
    }

    /**
     * Comme executerSansCasser(), et une donnée refusée par l'action (une
     * adresse qui ne peut pas servir, un mot de passe trop court) s'affiche
     * aussi, message par message.
     */
    public static function executerLeGeste(Closure $geste): void
    {
        try {
            static::executerSansCasser($geste);
        } catch (ValidationException $refus) {
            Notification::make()->title(implode(' ', array_merge(...array_values($refus->errors()))))->danger()->send();
        }
    }

    protected static function auteur(): User
    {
        $auteur = auth()->user();

        abort_unless($auteur instanceof User, 403);

        return $auteur;
    }

    private static function peut(string $geste, User $compte): bool
    {
        return auth()->user()?->can($geste, $compte) ?? false;
    }
}
