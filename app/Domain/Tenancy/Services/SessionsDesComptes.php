<?php

namespace App\Domain\Tenancy\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ferme les sessions d'un compte, partout : celles de la table des
 * sessions (pilote database, voir config/session.php), y compris sur les
 * routes hors des panneaux, que AuthenticateSession ne protège pas, et le
 * « se souvenir de moi », par un nouveau remember_token. Chaque écriture
 * d'un mot de passe, chaque suspension, chaque retrait et chaque refus d'un
 * compte s'y termine ; seule la session courante de la personne qui change
 * elle-même son mot de passe peut être gardée. La table des sessions
 * appartient aux comptes, pas à un magasin : elle se filtre par compte.
 */
final class SessionsDesComptes
{
    public function fermer(User $compte, ?string $sessionAGarder = null): void
    {
        DB::table('sessions')
            ->where('user_id', $compte->getKey())
            ->when($sessionAGarder !== null, fn ($sessions) => $sessions->where('id', '<>', $sessionAGarder))
            ->delete();

        $compte->forceFill(['remember_token' => Str::random(60)])->save();
    }
}
