<?php

namespace App\Domain\Tenancy\Services;

use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use SensitiveParameter;

/**
 * Le fournisseur des comptes de l'authentification (config/auth.php) : la
 * connexion, « Mot de passe oublié » et la réinitialisation du mot de
 * passe cherchent le compte par son adresse e-mail en minuscules, sans
 * espaces autour, telle qu'elle est enregistrée (voir
 * User::normaliserEmail()) : « Alice@Exemple.fr » ouvre le compte
 * alice@exemple.fr.
 */
class FournisseurDesComptes extends EloquentUserProvider
{
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(#[SensitiveParameter] array $credentials): ?Authenticatable
    {
        if (is_string($credentials['email'] ?? null)) {
            $credentials['email'] = User::normaliserEmail($credentials['email']);
        }

        return parent::retrieveByCredentials($credentials);
    }
}
