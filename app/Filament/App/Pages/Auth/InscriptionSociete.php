<?php

namespace App\Filament\App\Pages\Auth;

use App\Domain\Tenancy\Actions\CreerSocieteAction;
use App\Domain\Tenancy\Enums\ResultatVerification;
use App\Domain\Tenancy\Mail\CompteExistantMail;
use App\Domain\Tenancy\Mail\DemandeInscriptionMail;
use App\Domain\Tenancy\Models\DemandeInscription;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Rules\SirenValide;
use App\Domain\Tenancy\Services\ControleSirene;
use App\Domain\Tenancy\Services\FournisseurDesComptes;
use App\Domain\Tenancy\Support\RegleDuMotDePasse;
use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Facades\Filament;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Http\Responses\Auth\Contracts\RegistrationResponse;
use Filament\Pages\Auth\Register;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * L'inscription d'une société, en deux temps. Ici, la demande : le SIREN
 * de l'entreprise, l'adresse e-mail et le mot de passe de son propriétaire,
 * rien de plus ; aucun magasin : tous se créent ensuite dans l'espace de
 * la société (voir CreerMagasin). Rien n'est créé : un lien valable 24
 * heures part à l'adresse donnée, et c'est sa confirmation qui crée la
 * société et fait partir le courrier recommandé à son représentant légal
 * (voir ConfirmerInscriptionController et CreerSocieteAction).
 *
 * Un SIREN n'appartient qu'à une seule société : un SIREN déjà pris (une
 * inscription qui attend son code, ou une société validée) est refusé par
 * le seul message CreerSocieteAction::MESSAGE_SIREN_PRIS, qui dit où est
 * le code s'il s'agit de l'entreprise de la personne, sans rien révéler
 * d'autre. Le répertoire public Sirene de l'INSEE est consulté tout de
 * suite (voir ControleSirene) : une entreprise cessée ou un numéro inconnu
 * sont refusés avec leur motif ; quand l'INSEE ne répond pas, la demande
 * est acceptée et la confirmation le reconsulte. Que l'adresse soit déjà
 * celle d'un propriétaire ou non, l'écran répond toujours la même chose
 * (MESSAGE_NEUTRE) : l'adresse de connexion d'un propriétaire ne reçoit
 * aucun lien d'inscription, mais un e-mail qui lui dit qu'elle a déjà un
 * compte (voir CompteExistantMail) ; l'adresse de contact d'un autre
 * compte n'empêche rien. Le mot de passe choisi suit la règle du mot de
 * passe choisi, sans l'identifiant, qui n'existe pas encore (voir
 * RegleDuMotDePasse) : le tirage de l'identifiant écartera la valeur égale
 * à ce mot de passe (voir GenerateurDIdentifiants).
 *
 * La page est ouverte à tous : deux demandes par minute et par adresse IP
 * (rateLimit() de Filament), vingt par heure et par adresse IP, trois par
 * heure pour un même SIREN avec une même adresse e-mail ; au-delà, le même
 * message neutre, sans rien envoyer. Le courrier, lui, ne part qu'après la
 * confirmation de l'adresse, dans la limite de courriers par adresse IP de
 * la confirmation. Aucun champ n'est validé avant l'envoi du formulaire.
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

        if (Societe::sirenPris($donnees['siren'])) {
            throw ValidationException::withMessages(['data.siren' => CreerSocieteAction::MESSAGE_SIREN_PRIS]);
        }

        $this->refuserCeQueSireneRefuse($donnees['siren']);

        if (FournisseurDesComptes::proprietaireDeLAdresse($email) !== null) {
            $panneau = Filament::getPanel('app');
            Mail::to($email)->send(new CompteExistantMail(
                self::adresseDeLApplication($panneau->generateRouteName('auth.login')),
                self::adresseDeLApplication($panneau->generateRouteName('auth.password-reset.request')),
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

    /**
     * Le mot de passe du propriétaire, suivant la règle du mot de passe
     * choisi, sans l'identifiant, qui n'existe pas encore ; haché dès la
     * demande (voir CreerSocieteAction).
     */
    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label('Votre mot de passe')
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->rules(RegleDuMotDePasse::regles(null))
            ->helperText('Au moins 8 caractères ; ni un seul caractère répété, ni une suite de chiffres.')
            ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
            ->same('passwordConfirmation');
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
