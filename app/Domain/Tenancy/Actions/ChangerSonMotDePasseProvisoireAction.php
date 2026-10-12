<?php

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Services\SessionsDesComptes;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use LogicException;
use SensitiveParameter;

/**
 * La personne remplace le mot de passe provisoire que la société lui a
 * remis par le sien, à sa première connexion (voir
 * ChangerMotDePasseProvisoire), avant toute autre page : le provisoire et
 * son expiration s'effacent, ses autres sessions se ferment et son « se
 * souvenir de moi » se renouvelle ; seule la session courante reste.
 * Un provisoire expiré n'ouvre plus rien : la personne en demande un
 * nouveau à qui l'a créé. Changer son mot de passe provisoire ne confirme
 * pas son adresse : elle peut encore refuser ce compte (voir
 * User::peutRefuserSonCompte()).
 */
class ChangerSonMotDePasseProvisoireAction
{
    public const MESSAGE_EXPIRE = 'Votre mot de passe provisoire a expiré : demandez-en un nouveau à la personne qui a créé votre compte.';

    public const MESSAGE_IDENTIQUE = 'Choisissez un mot de passe différent du mot de passe provisoire.';

    public function __construct(private readonly SessionsDesComptes $sessions) {}

    /**
     * @throws ValidationException
     * @throws LogicException quand le mot de passe n'est plus provisoire
     */
    public function handle(User $compte, #[SensitiveParameter] string $nouveauMotDePasse, ?string $sessionCourante = null): void
    {
        Validator::make(['password' => $nouveauMotDePasse], ['password' => ['required', 'string', Password::default()]])->validate();

        DB::transaction(function () use ($compte, $nouveauMotDePasse, $sessionCourante): void {
            $relu = User::query()->whereKey($compte->getKey())->lockForUpdate()->firstOrFail();

            if (! $relu->doitChangerSonMotDePasse()) {
                throw new LogicException('Votre mot de passe n\'est plus provisoire.');
            }

            if ($relu->sonMotDePasseProvisoireAExpire()) {
                throw ValidationException::withMessages(['password' => self::MESSAGE_EXPIRE]);
            }

            if (Hash::check($nouveauMotDePasse, $relu->password)) {
                throw ValidationException::withMessages(['password' => self::MESSAGE_IDENTIQUE]);
            }

            $relu->forceFill([
                'password' => $nouveauMotDePasse,
                'mot_de_passe_a_changer' => false,
                'mot_de_passe_provisoire_expire_le' => null,
            ])->save();
            $this->sessions->fermer($relu, $sessionCourante);
        });

        $compte->refresh();
    }
}
