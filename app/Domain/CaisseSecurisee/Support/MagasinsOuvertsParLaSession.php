<?php

namespace App\Domain\CaisseSecurisee\Support;

use Illuminate\Contracts\Session\Session;

/**
 * Les magasins dont la session de l'utilisateur a ouvert le panneau, tels
 * que la session les retient : l'ouverture de session (JET 80) ne s'y
 * inscrit qu'une fois par magasin (voir JournaliserOuvertureDeSession), et
 * la déconnexion inscrit la fin de session (JET 40) dans chacun d'eux
 * (voir JournaliserFinDeSession).
 *
 * La liste vit dans la session elle-même : une session expirée ou
 * invalidée l'emporte avec elle, et la session suivante repart d'une liste
 * vide.
 */
final class MagasinsOuvertsParLaSession
{
    private const CLE = 'caisse_securisee.magasins_ouverts';

    /**
     * @return list<int>
     */
    public static function de(Session $session): array
    {
        return array_values(array_map('intval', (array) $session->get(self::CLE, [])));
    }

    public static function contient(Session $session, int $magasinId): bool
    {
        return in_array($magasinId, self::de($session), true);
    }

    public static function noter(Session $session, int $magasinId): void
    {
        $session->put(self::CLE, array_values(array_unique([...self::de($session), $magasinId])));
    }

    public static function oublier(Session $session): void
    {
        $session->forget(self::CLE);
    }
}
