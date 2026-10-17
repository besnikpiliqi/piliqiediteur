<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Services\JetonsDuLien;
use App\Domain\Tenancy\Services\SessionsDesComptes;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use LogicException;
use SensitiveParameter;

/**
 * La personne choisit son mot de passe sur la page d'un lien envoyé à son
 * adresse (voir PageDuLien) : celui de « Mot de passe oublié », ou celui
 * que sa société lui a fait envoyer pour renouveler son accès. Dans une
 * seule transaction, sous le verrou de la ligne du compte relue : le jeton
 * du lien se consomme (il ne sert qu'une fois), le mot de passe devient le
 * sien, définitif, le lien suivi confirme son adresse (voir
 * User::aConfirmeSonAdresse() : le compte ne pourra plus être refusé),
 * toutes ses sessions se ferment et son « se souvenir de moi » se
 * renouvelle. Personne d'autre que la personne ne connaît ce mot de passe.
 *
 * Un compte actif peut ensuite se connecter ; un compte retiré (par la
 * société ou par son propre refus) n'ouvre aucun panneau, mais pourra créer
 * sa propre société ou accepter un rattachement avec ce mot de passe. Un
 * compte suspendu ne choisit pas de mot de passe : seule sa société, qui l'a
 * suspendu, le réactive.
 */
class ChoisirSonMotDePasseParLeLienAction
{
    public const MESSAGE_SUSPENDU = 'Votre compte est suspendu : seule votre société peut le réactiver.';

    public function __construct(
        private readonly JetonsDuLien $jetons,
        private readonly SessionsDesComptes $sessions,
    ) {}

    /**
     * @throws AuthorizationException quand le lien n'est plus valable
     * @throws LogicException quand le compte est suspendu
     * @throws ValidationException
     */
    public function handle(User $compte, #[SensitiveParameter] string $jeton, #[SensitiveParameter] string $nouveauMotDePasse): void
    {
        Validator::make(['password' => $nouveauMotDePasse], ['password' => ['required', 'string', Password::default()]])->validate();

        DB::transaction(function () use ($compte, $jeton, $nouveauMotDePasse): void {
            $relu = User::query()->whereKey($compte->getKey())->lockForUpdate()->firstOrFail();

            if ($relu->estSuspendu()) {
                throw new LogicException(self::MESSAGE_SUSPENDU);
            }

            if (! $this->jetons->consommer($relu, $jeton)) {
                throw new AuthorizationException(RefuserSonCompteAction::MESSAGE_LIEN_INVALIDE);
            }

            $relu->forceFill([
                'password' => $nouveauMotDePasse,
                'mot_de_passe_a_changer' => false,
                'mot_de_passe_provisoire_expire_le' => null,
                'provisoire_donne_par' => null,
                'email_verified_at' => now(),
            ])->save();
            $this->sessions->fermer($relu);
        });

        $compte->refresh();
    }
}
