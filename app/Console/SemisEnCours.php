<?php

namespace App\Console;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Le semis des données de démonstration en cours (php artisan
 * migrate:fresh --seed, en développement, voir DatabaseSeeder), que le
 * planificateur lit avant chaque tâche (voir bootstrap/app.php) : tant
 * qu'il dure, aucune tâche planifiée ne part, même si php artisan
 * schedule:work tourne. Le semis déroule une histoire qui finit la veille
 * et exécute lui-même, à chaque minuit de cette histoire, les tâches du
 * calendrier des magasins ; une tâche partie à l'heure réelle, comme la
 * clôture automatique des caisses, verrouillerait les registres des
 * magasins semés à cette heure, et le semis, qui date ses gestes avant
 * elle, s'arrêterait au milieu. La marque vit dans le cache de
 * l'application, que le planificateur partage (CACHE_STORE=database dans
 * .env.example), et s'efface à la fin du semis, réussi ou non, ou au plus
 * tard après DUREE_MAXIMALE secondes si son processus est arrêté net.
 */
final class SemisEnCours
{
    public const CLE = 'piliqi:semis-en-cours';

    /**
     * La durée au-delà de laquelle la marque s'efface d'elle-même : bien
     * plus que les quelques minutes que dure un semis.
     */
    public const DUREE_MAXIMALE = 3600;

    /**
     * Exécute $semis, marqué en cours pendant toute sa durée.
     *
     * @template TResultat
     *
     * @param  Closure(): TResultat  $semis
     * @return TResultat
     */
    public static function pendant(Closure $semis): mixed
    {
        Cache::put(self::CLE, true, self::DUREE_MAXIMALE);

        try {
            return $semis();
        } finally {
            Cache::forget(self::CLE);
        }
    }

    /**
     * Vrai tant qu'un semis des données de démonstration est en cours.
     */
    public static function estEnCours(): bool
    {
        return Cache::get(self::CLE) === true;
    }
}
