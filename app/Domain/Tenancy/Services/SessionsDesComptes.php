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
 * d'un mot de passe, chaque suspension, chaque retrait d'un compte et la
 * transmission de la propriété, pour l'ancien propriétaire, s'y terminent ;
 * seule la session courante de la personne qui change elle-même son mot de
 * passe peut être gardée. La table des sessions appartient aux comptes,
 * pas à un magasin : elle se filtre par compte.
 */
final class SessionsDesComptes
{
    public function fermer(User $compte, ?string $sessionAGarder = null): void
    {
        $this->fermerAvantDEnregistrer($compte, $sessionAGarder);
        $compte->save();
    }

    /**
     * Ferme les sessions du compte et lui tire un nouveau remember_token,
     * sans l'enregistrer : l'appelant enregistre le compte avec ses autres
     * changements, en une seule écriture de sa ligne. Une seconde écriture
     * de la même ligne dans une transaction ferait contrôler par
     * PostgreSQL chacune de ses clés étrangères, en verrouillant en partage
     * les comptes qu'elles désignent (son créateur, l'auteur de son
     * statut) : un geste qui tient l'un d'eux en attendant ce compte, comme
     * ceux du propriétaire sur un expert-comptable de la société,
     * s'interbloquerait avec elle.
     */
    public function fermerAvantDEnregistrer(User $compte, ?string $sessionAGarder = null): void
    {
        DB::table('sessions')
            ->where('user_id', $compte->getKey())
            ->when($sessionAGarder !== null, fn ($sessions) => $sessions->where('id', '<>', $sessionAGarder))
            ->delete();

        $compte->forceFill(['remember_token' => Str::random(60)]);
    }
}
