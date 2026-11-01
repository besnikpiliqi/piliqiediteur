<?php

namespace App\Domain\Calendrier\Connexions;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * Le fuseau par défaut de la base PostgreSQL : celui que reçoit toute
 * session avant que l'application ne fixe le sien (config/database.php),
 * donc celui de psql, d'une sauvegarde, d'un script ou d'un répartiteur
 * de connexions qui perd le réglage de la session. Une migration le fixe
 * à UTC pour la base courante (ALTER DATABASE), et caisse:diagnostiquer
 * vérifie celui que reçoit vraiment une session (pg_settings.reset_val,
 * jamais SHOW TIMEZONE, qui rend l'UTC que l'application fixe à chaque
 * connexion) : un réglage du rôle (ALTER ROLE) ou les options de
 * connexion le remplacent, et il remplace celui de postgresql.conf.
 * SQLite n'a pas de fuseau de session : rien à fixer ni à lire.
 *
 * Seul le propriétaire de la base (ou un superutilisateur) peut changer
 * ses réglages : un autre rôle qui lance les migrations les fait échouer
 * avec un message qui dit quoi faire, et le déploiement s'arrête au lieu
 * d'oublier ce réglage en silence.
 */
final class FuseauParDefautDeLaBase
{
    /**
     * Les noms sous lesquels PostgreSQL désigne l'UTC.
     */
    private const NOMS_DE_L_UTC = ['UTC', 'Etc/UTC'];

    /**
     * Le SQLSTATE d'un droit insuffisant (insufficient_privilege).
     */
    private const DROIT_INSUFFISANT = '42501';

    /**
     * Fixe à UTC le fuseau par défaut de la base courante, sous PostgreSQL
     * ; ne fait rien sous SQLite.
     *
     * @throws RuntimeException le rôle de la connexion n'a pas le droit de changer les réglages de la base
     */
    public static function fixerAUtc(Connection $connexion): void
    {
        if ($connexion->getDriverName() !== 'pgsql') {
            return;
        }

        $session = $connexion->selectOne('select current_database() as base, current_user as role');

        try {
            $connexion->unprepared("do \$\$ begin execute format('alter database %I set timezone to %L', current_database(), 'UTC'); end \$\$");
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== self::DROIT_INSUFFISANT) {
                throw $exception;
            }

            throw new RuntimeException(sprintf(
                'Le fuseau par défaut de la base « %1$s » n\'a pas pu être fixé à UTC : le rôle « %2$s », qui lance les migrations, n\'est pas propriétaire de cette base. '
                .'Lancez les migrations avec le rôle propriétaire de la base, ou faites-la lui donner par son propriétaire actuel ou un superutilisateur (ALTER DATABASE %3$s OWNER TO %4$s;), puis relancez php artisan migrate. '
                .'Ce fuseau protège les sessions ouvertes hors de l\'application (psql, sauvegardes, scripts), qui liraient sinon les horodatages dans un autre fuseau.',
                $session->base,
                $session->role,
                self::identifiant($session->base),
                self::identifiant($session->role),
            ), 0, $exception);
        }
    }

    /**
     * Retire le fuseau par défaut de la base courante, sous PostgreSQL : le
     * retour arrière de fixerAUtc().
     */
    public static function retirer(Connection $connexion): void
    {
        if ($connexion->getDriverName() !== 'pgsql') {
            return;
        }

        $connexion->unprepared("do \$\$ begin execute format('alter database %I reset timezone', current_database()); end \$\$");
    }

    /**
     * Le nom de l'UTC que reçoit une session de cette connexion sous
     * PostgreSQL ; null sous SQLite.
     *
     * @throws RuntimeException une session y reçoit un autre fuseau
     */
    public static function exigerUtc(Connection $connexion): ?string
    {
        if ($connexion->getDriverName() !== 'pgsql') {
            return null;
        }

        $nom = (string) $connexion->selectOne("select reset_val from pg_settings where name = 'TimeZone'")->reset_val;

        if (! in_array($nom, self::NOMS_DE_L_UTC, true)) {
            throw new RuntimeException("Le fuseau par défaut de la base est « {$nom} » : les migrations le fixent à UTC pour la base (php artisan migrate) ; retirez tout autre fuseau que fixent le rôle (ALTER ROLE … SET timezone) ou les options de connexion, pour les sessions ouvertes hors de l'application.");
        }

        return $nom;
    }

    /**
     * Un nom de base ou de rôle écrit en identifiant PostgreSQL, entre
     * guillemets, à recopier tel quel.
     */
    private static function identifiant(string $nom): string
    {
        return '"'.str_replace('"', '""', $nom).'"';
    }
}
