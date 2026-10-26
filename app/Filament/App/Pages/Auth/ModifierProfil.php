<?php

namespace App\Filament\App\Pages\Auth;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Actions\ChangerSonMotDePasseAction;
use App\Domain\Tenancy\Actions\DemanderUneNouvelleAdresseAction;
use App\Domain\Tenancy\Enums\RoleSociete;
use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Auth\EditProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Chacun change ici son nom, son adresse e-mail et son mot de passe, en
 * donnant toujours son mot de passe actuel : une caisse restée ouverte ne
 * suffit pas à s'approprier le compte. Personne ne les change pour un
 * autre. Son identifiant de connexion s'y lit et ne se modifie pas ; tant
 * que son mot de passe est encore son identifiant, la page le lui rappelle.
 *
 * Le nom change tout de suite. Le nouveau mot de passe est facultatif :
 * laissé vide, l'ancien reste ; changé, il suit la règle du mot de passe
 * choisi (voir RegleDuMotDePasse), n'est plus l'identifiant, et ferme
 * toutes les autres sessions du compte, partout, celle-ci restant (voir
 * ChangerSonMotDePasseAction).
 *
 * L'adresse du propriétaire est son adresse de connexion : une nouvelle
 * adresse ne prend effet qu'après un clic sur le lien envoyé à cette
 * adresse, valable 24 heures, l'ancienne étant alors prévenue (voir
 * DemanderUneNouvelleAdresseAction et ConfirmerNouvelleAdresseAction) ; elle
 * doit être libre parmi les propriétaires, sans que le refus dise à qui
 * elle appartient. Le propriétaire qui n'a encore aucune adresse de
 * connexion (celle qu'il a choisie en saisissant le code du courrier
 * attend son lien, ou ce lien n'a pas pu partir) y lit où en est ce lien
 * et en redemande un, pour cette adresse ou une autre ; celui d'une
 * inscription close n'en a plus et n'en demande plus. L'adresse de tout autre compte n'est qu'un contact,
 * facultatif, qui s'enregistre aussitôt, sans confirmation, et peut être
 * celle d'un autre compte : c'est le seul endroit où elle s'écrit, jamais
 * par qui a créé le compte. Elle s'enregistre en minuscules (voir
 * User::normaliserEmail()).
 *
 * Le registre fiscal fige le nom de l'opérateur dans le contenu signé de
 * chaque enregistrement : un changement de nom n'y réécrit rien.
 */
class ModifierProfil extends EditProfile
{
    public const RAPPEL_IDENTIFIANT = 'Votre mot de passe est encore votre identifiant : toute personne qui le connaît peut se connecter à votre place. Choisissez-en un à vous ci-dessous.';

    /**
     * La nouvelle adresse de connexion que l'enregistrement vient de
     * demander, en attente de confirmation.
     */
    private ?string $adresseDemandee = null;

    /**
     * @return array<int|string, string|Form>
     */
    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getIdentifiantFormComponent(),
                        $this->getRappelFormComponent(),
                        $this->getNameFormComponent(),
                        $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                        $this->getMotDePasseActuelFormComponent(),
                    ])
                    ->operation('edit')
                    ->model($this->getUser())
                    ->statePath('data')
                    ->inlineLabel(! static::isSimple()),
            ),
        ];
    }

    protected function getIdentifiantFormComponent(): Component
    {
        return Placeholder::make('identifiant')
            ->label('Votre identifiant de connexion')
            ->content(fn (): string => $this->compte()->identifiantDeConnexion() ?? '—');
    }

    protected function getRappelFormComponent(): Component
    {
        return Placeholder::make('rappel')
            ->hiddenLabel()
            ->content(self::RAPPEL_IDENTIFIANT)
            ->visible(fn (): bool => $this->compte()->mot_de_passe_est_l_identifiant);
    }

    protected function getEmailFormComponent(): Component
    {
        if (! $this->compte()->estProprietaire()) {
            return TextInput::make('email')
                ->label('Adresse e-mail de contact (facultative)')
                ->email()
                ->maxLength(255)
                ->adresseDeConnexion()
                ->helperText('Un simple contact : elle ne sert ni à vous connecter, ni à retrouver votre accès, que vous redonne la personne qui gère votre compte.');
        }

        if ($this->compte()->societe()->value('close_le') !== null) {
            return Placeholder::make('adresse_de_connexion')
                ->label('Adresse e-mail de connexion')
                ->content(DemanderUneNouvelleAdresseAction::MESSAGE_INSCRIPTION_CLOSE);
        }

        return TextInput::make('email')
            ->label('Adresse e-mail de connexion')
            ->email()
            ->required(fn (): bool => $this->compte()->aUneAdresseDeConnexion())
            ->maxLength(255)
            ->adresseDeConnexion()
            ->unique(table: 'users', column: 'email', ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('role_societe', RoleSociete::Proprietaire->value))
            ->helperText(fn (): ?string => $this->etatDeLAdresseEnAttente())
            ->validationMessages([
                'unique' => 'Cette adresse e-mail ne peut pas être utilisée.',
            ]);
    }

    /**
     * Où en est l'adresse de connexion en attente du propriétaire : celle
     * qui remplacera la sienne, ou, sans adresse de connexion, celle qu'il
     * a choisie, dont le lien se suit connecté à ce compte ou se redemande
     * ici.
     */
    private function etatDeLAdresseEnAttente(): ?string
    {
        $compte = $this->compte();
        $enAttente = $compte->nouvelle_adresse !== null && $compte->nouvelle_adresse_expire_le?->isFuture();

        if ($compte->aUneAdresseDeConnexion()) {
            return $enAttente ? "Nouvelle adresse en attente : {$compte->nouvelle_adresse}. Elle remplacera celle-ci quand vous aurez suivi le lien qui lui a été envoyé." : null;
        }

        $echeance = $compte->nouvelle_adresse_expire_le === null ? null : Calendrier::deParis()->afficher($compte->nouvelle_adresse_expire_le, 'd/m/Y à H:i');

        return match (true) {
            $enAttente => "Votre compte n'a pas encore d'adresse de connexion : suivez, en étant connecté à ce compte, le lien envoyé à {$compte->nouvelle_adresse}, valable jusqu'au {$echeance}. D'ici là, connectez-vous avec votre identifiant. Pour recevoir un nouveau lien, saisissez l'adresse ici et enregistrez.",
            $compte->nouvelle_adresse !== null => "Le lien envoyé à {$compte->nouvelle_adresse} a expiré : saisissez l'adresse ici et enregistrez pour en recevoir un nouveau. D'ici là, connectez-vous avec votre identifiant.",
            default => 'Votre compte n\'a pas encore d\'adresse de connexion : saisissez-la ici et enregistrez, puis suivez, en étant connecté à ce compte, le lien qui lui sera envoyé. D\'ici là, connectez-vous avec votre identifiant.',
        };
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label('Nouveau mot de passe')
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->autocomplete('new-password')
            ->helperText('Au moins 8 caractères ; ni votre identifiant, ni un seul caractère répété, ni une suite de chiffres.')
            ->rules(fn (): array => ['nullable', 'string', new RegleDuMotDePasse($this->compte()->identifiantDeConnexion())])
            ->dehydrated(fn ($state): bool => filled($state))
            ->live(debounce: 500)
            ->same('passwordConfirmation');
    }

    protected function getMotDePasseActuelFormComponent(): Component
    {
        return TextInput::make('mot_de_passe_actuel')
            ->label('Mot de passe actuel')
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->autocomplete('current-password')
            ->required()
            ->rule('current_password:'.Filament::getAuthGuard())
            ->validationMessages([
                'required' => 'Saisissez votre mot de passe actuel.',
                'current_password' => 'Le mot de passe actuel est incorrect.',
            ])
            ->dehydrated(false);
    }

    /**
     * Enregistre le profil, en une transaction : une adresse de connexion
     * qui change part d'abord en confirmation (refusée, par le débit des
     * e-mails de la personne, rien ne s'écrit) ; une adresse de contact
     * s'écrit ; puis le nom et le mot de passe.
     */
    public function save(): void
    {
        $compte = $this->compte();
        $donnees = $this->form->getState();
        $adresse = filled($donnees['email'] ?? null) ? User::normaliserEmail((string) $donnees['email']) : null;
        $motDePasse = $donnees['password'] ?? null;
        $this->adresseDemandee = null;

        try {
            DB::transaction(function () use ($compte, $donnees, $adresse, $motDePasse): void {
                if ($compte->estProprietaire() && $adresse !== null && $adresse !== $compte->email) {
                    app(DemanderUneNouvelleAdresseAction::class)->handle($compte, $adresse);
                    $this->adresseDemandee = $adresse;
                }

                if (! $compte->estProprietaire() && $adresse !== $compte->email) {
                    $compte->forceFill(['email' => $adresse])->save();
                }

                $compte->forceFill(['name' => $donnees['name']])->save();

                if (filled($motDePasse)) {
                    app(ChangerSonMotDePasseAction::class)->handle($compte, (string) $motDePasse, request()->hasSession() ? request()->session()->getId() : null, 'data.password');
                }
            });
        } catch (LogicException $refus) {
            throw ValidationException::withMessages(['data.email' => $refus->getMessage()]);
        }

        if (filled($motDePasse) && request()->hasSession()) {
            request()->session()->put(['password_hash_'.Filament::getAuthGuard() => $compte->refresh()->getAuthPassword()]);
        }

        $this->data['password'] = null;
        $this->data['passwordConfirmation'] = null;
        $this->data['mot_de_passe_actuel'] = null;
        $this->data['email'] = $compte->refresh()->email;

        $this->getSavedNotification()?->send();
    }

    protected function getSavedNotification(): ?Notification
    {
        if ($this->adresseDemandee === null) {
            return parent::getSavedNotification();
        }

        return Notification::make()
            ->success()
            ->title('Profil enregistré')
            ->body("Un lien de confirmation, valable 24 heures, vient d'être envoyé à {$this->adresseDemandee} : votre adresse de connexion ne changera qu'une fois ce lien suivi.");
    }

    private function compte(): User
    {
        $compte = $this->getUser();

        abort_unless($compte instanceof User, 403);

        return $compte;
    }
}
