<?php

namespace App\Filament\App\Pages\Auth;

use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Mail\CompteExistantMail;
use App\Domain\Tenancy\Mail\DemandeInscriptionMail;
use App\Domain\Tenancy\Models\DemandeInscription;
use App\Domain\Tenancy\Rules\SirenValide;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Http\Responses\Auth\Contracts\RegistrationResponse;
use Filament\Pages\Auth\Register;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * L'inscription d'une société, en deux temps. Ici, la demande : le SIREN
 * de l'entreprise et le compte de son propriétaire (nom, adresse de
 * connexion, mot de passe), sans aucun magasin : tous les magasins se
 * créent ensuite dans l'espace de la société (voir CreerMagasin). Rien
 * n'est créé : un lien valable 24 heures part à l'adresse donnée, et c'est
 * sa confirmation qui crée la société (voir ConfirmerInscriptionController
 * et CreerSocieteAction).
 *
 * Le répertoire public Sirene de l'INSEE est consulté tout de suite (voir
 * ControleSirene) : une entreprise cessée ou un numéro inconnu sont
 * refusés avec leur motif ; quand l'INSEE ne répond pas, la demande est
 * acceptée et le contrôle reprend à la confirmation. Rien d'autre n'est
 * vérifié dans le logiciel : que le SIREN soit déjà celui d'une autre
 * société ou l'adresse déjà celle d'un compte, l'écran répond toujours la
 * même chose (MESSAGE_NEUTRE). Une adresse déjà utilisée ne reçoit aucun
 * lien d'inscription, mais un e-mail qui lui dit qu'elle a déjà un compte
 * (voir CompteExistantMail) ; celle d'un compte retiré y reçoit le lien de
 * la page du lien, pour choisir son mot de passe puis créer sa propre
 * société avec ce compte.
 *
 * La page est ouverte à tous : deux demandes par minute et par adresse IP
 * (rateLimit() de Filament), vingt par heure et par adresse IP, trois par
 * heure pour un même SIREN avec une même adresse e-mail ; au-delà, le même
 * message neutre, sans rien envoyer. Aucune limite par SIREN seul : un
 * tiers ne sature pas le SIREN d'une entreprise. Aucun champ n'est validé
 * avant l'envoi du formulaire.
 */
class InscriptionSociete extends Register
{
    public const MESSAGE_NEUTRE = 'Si ces informations permettent l\'inscription, un e-mail vient d\'être envoyé à cette adresse. Le lien est valable 24 heures.';

    public const DEMANDES_PAR_HEURE_ET_PAR_ADRESSE_IP = 20;

    public const DEMANDES_PAR_HEURE_POUR_UN_SIREN_ET_UNE_ADRESSE = 3;

    protected static string $view = 'filament.app.pages.auth.inscription-societe';

    /**
     * La demande est partie : la page ne montre plus que MESSAGE_NEUTRE.
     */
    public bool $demandeEnvoyee = false;

    public function getTitle(): string|Htmlable
    {
        return 'Inscrire votre société';
    }

    public function getHeading(): string|Htmlable
    {
        return 'Inscrire votre société';
    }

    protected function getForms(): array
    {
        return [
            'form' => $this->form(
                $this->makeForm()
                    ->schema([
                        $this->getSirenFormComponent(),
                        $this->getNameFormComponent(),
                        $this->getEmailFormComponent(),
                        $this->getPasswordFormComponent(),
                        $this->getPasswordConfirmationFormComponent(),
                    ])
                    ->statePath('data'),
            ),
        ];
    }

    public function register(): ?RegistrationResponse
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return null;
        }

        $donnees = $this->form->getState();
        $email = User::normaliserEmail($donnees['email']);

        if ($this->limiteAtteinte($donnees['siren'], $email)) {
            $this->terminer();

            return null;
        }

        $this->refuserCeQueSireneRefuse($donnees['siren']);

        $compte = User::query()->where('email', $email)->first();

        if ($compte !== null) {
            $panneau = Filament::getPanel('app');
            Mail::to($email)->send(new CompteExistantMail(
                self::adresseDeLApplication($panneau->generateRouteName('auth.login')),
                self::adresseDeLApplication($panneau->generateRouteName('auth.password-reset.request')),
                $compte->estRetire() ? $panneau->getResetPasswordUrl(Password::broker(Filament::getAuthPasswordBroker())->createToken($compte), $compte) : null,
                (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire'),
            ));
            $this->terminer();

            return null;
        }

        $jeton = Str::random(64);
        $demande = tap((new DemandeInscription)->forceFill([
            'email' => $email,
            'jeton_hache' => DemandeInscription::empreinte($jeton),
            'donnees' => [
                'siren' => $donnees['siren'],
                'nom' => $donnees['name'],
                'mot_de_passe' => $donnees['password'],
            ],
            'expire_le' => now()->addHours(DemandeInscription::DUREE_DE_VALIDITE_EN_HEURES),
        ]))->save();

        Mail::to($email)->send(new DemandeInscriptionMail(self::lienDeConfirmation($jeton), $demande->expire_le));
        $this->terminer();

        return null;
    }

    /**
     * Le lien de confirmation (voir adresseDeLApplication()).
     */
    public static function lienDeConfirmation(string $jeton): string
    {
        return self::adresseDeLApplication('inscription.afficher', ['jeton' => $jeton]);
    }

    /**
     * L'adresse d'une page du logiciel telle qu'un e-mail de l'inscription
     * la donne : construite depuis APP_URL, jamais depuis l'adresse de la
     * requête, dont l'en-tête Host vient de qui remplit cette page ouverte
     * à tous.
     *
     * @param  array<string, mixed>  $parametres
     */
    public static function adresseDeLApplication(string $route, array $parametres = []): string
    {
        return rtrim((string) config('app.url'), '/').route($route, $parametres, absolute: false);
    }

    protected function getSirenFormComponent(): Component
    {
        return TextInput::make('siren')
            ->label('SIREN de votre entreprise')
            ->helperText('Les 9 chiffres qui identifient votre entreprise au répertoire Sirene de l\'INSEE.')
            ->required()
            ->maxLength(9)
            ->rule(new SirenValide)
            ->autofocus();
    }

    /**
     * L'adresse de connexion du propriétaire, en minuscules comme elle sera
     * enregistrée. Son existence n'est jamais vérifiée ici : l'écran ne dit
     * pas si elle est déjà celle d'un compte.
     */
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label('Votre adresse e-mail')
            ->email()
            ->required()
            ->maxLength(255)
            ->adresseDeConnexion();
    }

    protected function getNameFormComponent(): Component
    {
        return parent::getNameFormComponent()->label('Votre nom')->autofocus(false);
    }

    /**
     * Les limites d'essais par adresse IP, et pour un même SIREN avec une
     * même adresse e-mail, chacune sur une heure : chaque demande compte.
     */
    private function limiteAtteinte(string $siren, string $email): bool
    {
        $cleIp = 'inscription-ip:'.sha1((string) request()->ip());
        $clePaire = 'inscription-siren-adresse:'.sha1($siren.'|'.$email);

        $atteinte = RateLimiter::tooManyAttempts($cleIp, self::DEMANDES_PAR_HEURE_ET_PAR_ADRESSE_IP)
            || RateLimiter::tooManyAttempts($clePaire, self::DEMANDES_PAR_HEURE_POUR_UN_SIREN_ET_UNE_ADRESSE);

        RateLimiter::hit($cleIp, 3600);
        RateLimiter::hit($clePaire, 3600);

        return $atteinte;
    }

    /**
     * Une entreprise cessée ou inconnue d'après le répertoire public :
     * refusée avec le motif du répertoire. L'INSEE qui ne répond pas
     * n'empêche rien.
     *
     * @throws ValidationException
     */
    private function refuserCeQueSireneRefuse(string $siren): void
    {
        $unite = app(ControleSirene::class)->unite($siren);

        if ($unite['resultat'] === ResultatVerification::Negative) {
            throw ValidationException::withMessages(['data.siren' => $unite['motif']]);
        }
    }

    private function terminer(): void
    {
        $this->demandeEnvoyee = true;
        $this->form->fill();
    }
}
