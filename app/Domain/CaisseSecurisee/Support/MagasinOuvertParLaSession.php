<?php

namespace App\Domain\CaisseSecurisee\Support;

use Illuminate\Contracts\Session\Session;

/**
 * Le magasin dont la session de l'utilisateur a ouvert le panneau, tel que
 * la session le retient : l'ouverture de session (JET 80) ne s'y inscrit
 * qu'une fois (voir JournaliserOuvertureDeSession), et la déconnexion y
 * inscrit la fin de session (JET 40, voir JournaliserFinDeSession). Un
 * compte n'appartenant qu'à un seul magasin, sa session n'en ouvre qu'un.
 *
 * Il vit dans la session elle-même : une session expirée ou invalidée
 * l'emporte avec elle, et la session suivante repart sans magasin ouvert.
 */
final class MagasinOuvertParLaSession
{
    private const CLE = 'caisse_securisee.magasin_ouvert';

    public static function de(Session $session): ?int
    {
        $magasinId = $session->get(self::CLE);

        return $magasinId === null ? null : (int) $magasinId;
    }

    public static function noter(Session $session, int $magasinId): void
    {
        $session->put(self::CLE, $magasinId);
    }

    public static function oublier(Session $session): void
    {
        $session->forget(self::CLE);
    }
}
