<?php

namespace App\Domain\Demonstration\Bases;

use App\Domain\Demonstration\SiteDePiliqi;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;

/**
 * Brancher la connexion par défaut (pgsql) sur une base du site de
 * démonstration (lot DEMO-COPIE, C.2 et C.6.4) : son nom et son rôle
 * changent, puis chaque service déjà résolu sur l'ancienne base (le cache,
 * ses verrous, le limiteur de débit) est oublié, pour être refait sur la
 * nouvelle à son prochain usage. Rien de ce qui a servi à une base ne sert
 * à la suivante.
 */
final class BrancherUneBase
{
    /**
     * @param  array{database: string, username: string, password: string}  $acces
     */
    public static function brancher(array $acces): void
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        config([
            'database.connections.pgsql.database' => $acces['database'],
            'database.connections.pgsql.username' => $acces['username'],
            'database.connections.pgsql.password' => $acces['password'],
        ]);
        self::oublierLesServicesResolus();
    }

    /**
     * Le geste, sur la base donnée, par le rôle donné ; la connexion par
     * défaut revient ensuite à ce qu'elle était, même après un échec.
     *
     * @template T
     *
     * @param  array{database: string, username: string, password: string}  $acces
     * @param  Closure(): T  $geste
     * @return T
     */
    public static function pendant(array $acces, Closure $geste): mixed
    {
        $avant = [
            'database' => (string) config('database.connections.pgsql.database'),
            'username' => (string) config('database.connections.pgsql.username'),
            'password' => (string) config('database.connections.pgsql.password'),
        ];
        self::brancher($acces);

        try {
            return $geste();
        } finally {
            self::brancher($avant);
        }
    }

    /**
     * L'accès de demo_admin à une base.
     *
     * @return array{database: string, username: string, password: string}
     */
    public static function parLAdministration(string $base): array
    {
        return [
            'database' => $base,
            'username' => (string) config('database.connections.demo_admin.username'),
            'password' => (string) config('database.connections.demo_admin.password'),
        ];
    }

    /**
     * L'accès du rôle des pages (demo_app) à une base.
     *
     * @return array{database: string, username: string, password: string}
     */
    public static function parLesPages(string $base): array
    {
        return [
            'database' => $base,
            'username' => (string) config('database.connections.registre.username'),
            'password' => (string) config('database.connections.registre.password'),
        ];
    }

    /**
     * Les services oubliés, dans le conteneur et derrière leurs façades,
     * qui gardent l'instance qu'elles ont résolue la première fois (un
     * travailleur qui passe d'une copie à l'autre, ou un test, garde le même
     * processus) : le limiteur de débit garde son magasin de cache, et
     * l'intergiciel des sessions (StartSession, unique dans le conteneur)
     * garde le gestionnaire des sessions qu'il a reçu : sans lui, la requête
     * suivante du même processus lirait sa session par l'ancien
     * gestionnaire, et la garde d'authentification par le nouveau, si bien
     * qu'une connexion faite dans l'une ne se retrouverait pas dans l'autre.
     */
    private static function oublierLesServicesResolus(): void
    {
        DB::purge('pgsql');
        Cache::forgetDriver(array_keys((array) config('cache.stores')));

        foreach (['cache.store', RateLimiter::class, 'session', 'session.store', StartSession::class] as $service) {
            app()->forgetInstance($service);
            Facade::clearResolvedInstance($service);
        }
    }
}
