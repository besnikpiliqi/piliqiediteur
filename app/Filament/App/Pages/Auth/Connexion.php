<?php

namespace App\Filament\App\Pages\Auth;

use App\Domain\Tenancy\Services\FournisseurDesComptes;
use App\Domain\Tenancy\Services\LimitationDesConnexions;
use App\Domain\Tenancy\Services\PostesConnus;
use App\Domain\Tenancy\Services\SuiviDesConnexions;
use App\Domain\Tenancy\Support\IdentifiantDeConnexion;
use App\Http\Middleware\MenerUnCompteSansMagasin;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\TextInput;
use Filament\Http\Responses\Auth\Contracts\LoginResponse;
use Filament\Pages\Auth\Login;
use Illuminate\Validation\ValidationException;

/**
 * La connexion aux deux panneaux, propre à PILIQI :
 * - un seul champ « Identifiant ou adresse e-mail » : l'identifiant de 8
 *   chiffres de tout compte, écrit avec ou sans espaces, tirets ou points,
 *   ou l'adresse de connexion du propriétaire (voir FournisseurDesComptes) ;
 *   une saisie qui n'a pas la forme d'un identifiant le dit, sans rien
 *   révéler ;
 * - la même réponse, au même délai, pour un identifiant inconnu, une
 *   adresse qui n'est pas celle d'un propriétaire, un mot de passe faux, un
 *   compte suspendu ou retiré, un mot de passe égal à l'identifiant tapé
 *   hors d'un poste reconnu, ou un compte qui n'entre pas dans ce panneau ;
 *   l'écran ne réaffiche pas l'identifiant après un échec ;
 * - la limitation des essais, qui ne compte que les échecs et n'impose
 *   jamais d'attente sur un poste connu du compte (voir
 *   LimitationDesConnexions et PostesConnus) ;
 * - « Se souvenir de moi » pour le seul compte du propriétaire, sur un
 *   appareil personnel : sans effet pour tout autre compte.
 * Une connexion réussie régénère la session, efface le compteur de sa
 * saisie, retient ce poste pour le compte, puis note la première et la
 * dernière connexion (voir SuiviDesConnexions) ; elle seule, jamais une
 * connexion refusée, aucun écouteur de connexion ne s'en chargeant.
 * Connectée, la personne va à la page qu'elle demandait, sinon à l'accueil
 * du panneau, même sans magasin (voir MenerUnCompteSansMagasin), ou au choix
 * de son mot de passe s'il le faut (voir ExigerSonPropreMotDePasse).
 */
class Connexion extends Login
{
    public const MESSAGE_ECHEC = 'Identifiant, adresse ou mot de passe incorrect.';

    public const MESSAGE_POSTE = 'Si votre mot de passe est encore votre identifiant, connectez-vous depuis un poste reconnu de votre magasin, puis choisissez votre mot de passe dans votre profil.';

    public function mount(): void
    {
        if (Filament::auth()->check()) {
            redirect()->intended(MenerUnCompteSansMagasin::accueil());
        }

        $this->form->fill();
    }

    /**
     * Le champ de la saisie, à la place de l'adresse e-mail de Filament :
     * l'identifiant de tout compte, ou l'adresse de connexion du
     * propriétaire.
     */
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('login')
            ->label('Identifiant ou adresse e-mail')
            ->helperText('Votre identifiant de 8 chiffres ; le propriétaire peut aussi utiliser son adresse e-mail.')
            ->required()
            ->maxLength(255)
            ->autocomplete('username')
            ->autofocus()
            ->extraInputAttributes(['tabindex' => 1]);
    }

    protected function getRememberFormComponent(): Component
    {
        return Checkbox::make('remember')->label('Rester connecté (compte du propriétaire seulement, sur un appareil personnel)');
    }

    public function authenticate(): ?LoginResponse
    {
        $donnees = $this->form->getState();
        $saisie = trim((string) $donnees['login']);
        $motDePasse = (string) $donnees['password'];
        $estUneAdresse = str_contains($saisie, '@');
        $normalisee = $estUneAdresse ? User::normaliserEmail($saisie) : IdentifiantDeConnexion::normaliser($saisie);

        if (! $estUneAdresse && ! IdentifiantDeConnexion::aLaForme($normalisee)) {
            throw ValidationException::withMessages(['data.login' => IdentifiantDeConnexion::MESSAGE_FORME]);
        }

        $requete = request();
        $adresseIp = (string) $requete->ip();
        $limitation = app(LimitationDesConnexions::class);
        $identifiants = ['login' => $normalisee, 'password' => $motDePasse];
        /** @var FournisseurDesComptes $fournisseur */
        $fournisseur = Filament::auth()->getProvider();
        $compte = $fournisseur->compteDeLaSaisie($identifiants);
        $nonce = $compte === null ? null : app(PostesConnus::class)->nonceDuCompte($requete, $compte);
        $nonce = $nonce !== null && $limitation->posteIgnore($nonce) ? null : $nonce;
        $attente = $limitation->attente($normalisee, $adresseIp, $nonce !== null);

        if ($attente > 0) {
            $this->oublierLaSaisie();

            throw ValidationException::withMessages(['data.login' => LimitationDesConnexions::message($attente)]);
        }

        $admis = Filament::auth()->attempt($identifiants, ($donnees['remember'] ?? false) === true && $compte?->estProprietaire() === true);
        $connecte = Filament::auth()->user();

        if ($admis && (! $connecte instanceof User || ! $connecte->canAccessPanel(Filament::getCurrentPanel()))) {
            Filament::auth()->logout();
            $admis = false;
        }

        if (! $admis || ! $connecte instanceof User) {
            $limitation->echec($normalisee, $adresseIp, $nonce, $compte, $motDePasse === $normalisee);
            $this->oublierLaSaisie();

            throw ValidationException::withMessages(['data.login' => $motDePasse === $normalisee ? self::MESSAGE_ECHEC.' '.self::MESSAGE_POSTE : self::MESSAGE_ECHEC]);
        }

        session()->regenerate();
        $limitation->reussite($normalisee, $adresseIp, $nonce);
        app(PostesConnus::class)->retenir($requete, $connecte);
        app(SuiviDesConnexions::class)->noterLaConnexion($connecte);

        return app(LoginResponse::class);
    }

    /**
     * Après un échec, l'écran ne garde ni l'identifiant ni le mot de passe.
     */
    private function oublierLaSaisie(): void
    {
        $this->data = [...($this->data ?? []), 'login' => null, 'password' => null];
    }
}
