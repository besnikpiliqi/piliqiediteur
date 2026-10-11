<?php

namespace App\Domain\CaisseSecurisee\Support;

use Illuminate\Contracts\Session\Session;

/**
 * Les magasins dont la session de l'utilisateur a ouvert le panneau, tels
 * que la session les retient : l'ouverture de session (JET 80) ne s'inscrit
 * qu'une fois par magasin et par session (voir
 * JournaliserOuvertureDeSession), et la déconnexion inscrit la fin de
 * session (JET 40) dans chacun d'eux (voir JournaliserFinDeSession). Le
 * propriétaire d'une société ouvre tour à tour plusieurs de ses magasins
 * dans une même session.
 *
 * Ils vivent dans la session elle-même : une session expirée ou invalidée
 * les emporte avec elle, et la session suivante repart sans magasin
 * ouvert.
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
        $session->put(self::CLE, [...self::de($session), $magasinId]);
    }

    public static function oublier(Session $session): void
    {
        $session->forget(self::CLE);
    }
}
