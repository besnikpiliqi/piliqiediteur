<?php

namespace App\Domain\Tenancy\Support;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use LogicException;
use SensitiveParameter;

/**
 * Le mot de passe que redemandent les gestes graves de la société :
 * transmettre la propriété (voir TransfererProprieteAction), fermer, céder
 * ou rouvrir un magasin, changer son SIRET (voir FermerMagasinAction,
 * RouvrirMagasinAction et ChangerSiretAction). Il se compare à celui que
 * la base donne pour l'auteur, relu par l'action : jamais à celui d'une
 * session restée ouverte.
 */
final class MotDePasseRedemande
{
    public const MESSAGE = 'Votre mot de passe est incorrect.';

    /**
     * @throws LogicException quand le mot de passe n'est pas celui du compte
     */
    public static function exiger(User $auteur, #[SensitiveParameter] string $motDePasse): void
    {
        if (! Hash::check($motDePasse, (string) User::query()->whereKey($auteur->getKey())->value('password'))) {
            throw new LogicException(self::MESSAGE);
        }
    }
}
