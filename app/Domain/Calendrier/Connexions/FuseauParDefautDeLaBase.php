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
 * connexion).
 *
 * Une session reçoit le premier fuseau fixé parmi, dans cet ordre : les
 * options de sa connexion, le réglage de son rôle dans la base (ALTER
 * ROLE … IN DATABASE), celui de son rôle (ALTER ROLE), celui de la base
 * (ALTER DATABASE), puis la configuration du serveur (postgresql.conf).
 * Le diagnostic lit les trois réglages du catalogue (pg_db_role_setting)
 * pour nommer la cause d'un fuseau qui n'est pas l'UTC et la commande qui
 * la corrige : la migration ne passe qu'une fois, et une sauvegarde
 * logique restaurée sans --create perd le réglage de la base, que
 * php artisan migrate ne refait donc pas.
 *
 * Seul le propriétaire de la base (ou un superutilisateur) peut changer
 * ses réglages : un autre rôle qui lance les migrations les fait échouer
 * avec un message qui dit quoi faire, et le déploiement s'arrête au lieu
 * d'oublier ce réglage en silence, sauf si la base est déjà à UTC.
 */
final class FuseauParDefautDeLaBase
{
    /**
     * Les noms sous lesquels PostgreSQL désigne l'UTC, en minuscules : la
     * casse n'y compte pas.
     */
    private const NOMS_DE_L_UTC = ['utc', 'etc/utc'];

    /**
     * Le SQLSTATE d'un droit insuffisant (insufficient_privilege).
     */
    private const DROIT_INSUFFISANT = '42501';

    /**
     * Fixe à UTC le fuseau par défaut de la base courante, sauf s'il l'est
     * déjà.
     *
     * @throws RuntimeException le rôle de la connexion n'a pas le droit de changer les réglages de la base
     */
    public static function fixerAUtc(Connection $connexion): void
    {
        $etat = self::etat($connexion);

        if (self::estLUtc($etat->fuseau_de_la_base)) {
            return;
        }

        try {
            $connexion->unprepared("do \$\$ begin execute format('alter database %I set timezone to %L', current_database(), 'UTC'); end \$\$");
        } catch (QueryException $exception) {
            if (($exception->errorInfo[0] ?? null) !== self::DROIT_INSUFFISANT) {
                throw $exception;
            }

            throw new RuntimeException(sprintf(
                'Le fuseau par défaut de la base « %1$s » n\'a pas pu être fixé à UTC : le rôle « %2$s », qui lance les migrations, n\'est pas propriétaire de cette base, qui appartient à « %3$s ». '
                .'Faites exécuter par « %3$s » ou un superutilisateur %4$s puis relancez php artisan migrate, qui trouvera ce réglage en place ; '
                .'ou faites donner la base au rôle « %2$s » par un superutilisateur (ALTER DATABASE %5$s OWNER TO %6$s;), puis relancez php artisan migrate. '
                .'Ce fuseau protège les sessions ouvertes hors de l\'application (psql, sauvegardes, scripts), qui liraient sinon les horodatages dans un autre fuseau.',
                $etat->base,
                $etat->role,
                $etat->proprietaire,
                self::commandeDeLaBase($etat->base),
                self::identifiant($etat->base),
                self::identifiant($etat->role),
            ), 0, $exception);
        }
    }

    /**
     * Retire le fuseau par défaut de la base courante : le retour arrière de
     * fixerAUtc().
     */
    public static function retirer(Connection $connexion): void
    {
        $connexion->unprepared("do \$\$ begin execute format('alter database %I reset timezone', current_database()); end \$\$");
    }

    /**
     * Le nom de l'UTC que reçoit une session de cette connexion.
     *
     * @throws RuntimeException une session y reçoit un autre fuseau : le message en dit la cause et la commande qui la corrige
     */
    public static function exigerUtc(Connection $connexion): string
    {
        $etat = self::etat($connexion);

        if (! self::estLUtc($etat->fuseau)) {
            throw new RuntimeException("Le fuseau par défaut que reçoit une session de la base « {$etat->base} » est « {$etat->fuseau} », et non UTC : ".self::causeEtRemede($etat).' Relancez ensuite php artisan caisse:diagnostiquer.');
        }

        return $etat->fuseau;
    }

    /**
     * La base courante, le rôle de la connexion, le propriétaire de la
     * base, le fuseau que reçoit une session (reset_val) et les trois
     * réglages du catalogue qui peuvent le fixer, chacun null s'il
     * n'existe pas.
     *
     * @return object{base: string, role: string, proprietaire: string, fuseau: string, fuseau_du_role_dans_la_base: ?string, fuseau_du_role: ?string, fuseau_de_la_base: ?string}
     */
    private static function etat(Connection $connexion): object
    {
        $reglage = static fn (string $base, string $role): string => '(select substr(c.reglage, 10) from pg_db_role_setting s cross join lateral unnest(s.setconfig) as c(reglage) '
            ."where s.setdatabase = {$base} and s.setrole = {$role} and c.reglage ilike 'timezone=%')";

        return $connexion->selectOne(
            'select d.datname as base, r.rolname as role, pg_get_userbyid(d.datdba) as proprietaire, '
            ."(select reset_val from pg_settings where name = 'TimeZone') as fuseau, "
            .$reglage('d.oid', 'r.oid').' as fuseau_du_role_dans_la_base, '
            .$reglage('0', 'r.oid').' as fuseau_du_role, '
            .$reglage('d.oid', '0').' as fuseau_de_la_base '
            .'from pg_database d, pg_roles r where d.datname = current_database() and r.rolname = session_user'
        );
    }

    /**
     * Ce qui donne à une session un fuseau qui n'est pas l'UTC, et la
     * commande qui le corrige : le premier réglage du catalogue dans
     * l'ordre où PostgreSQL les applique, ou, s'il est à UTC, les options
     * de la connexion.
     *
     * @param  object{base: string, role: string, proprietaire: string, fuseau_du_role_dans_la_base: ?string, fuseau_du_role: ?string, fuseau_de_la_base: ?string}  $etat
     */
    private static function causeEtRemede(object $etat): string
    {
        $reglages = array_filter([
            'role_dans_la_base' => $etat->fuseau_du_role_dans_la_base,
            'role' => $etat->fuseau_du_role,
            'base' => $etat->fuseau_de_la_base,
        ], static fn (?string $nom): bool => $nom !== null);
        $portee = array_key_first($reglages);
        $base = self::identifiant($etat->base);
        $role = self::identifiant($etat->role);
        $commandeDuProprietaire = "Faites exécuter par le propriétaire de la base (« {$etat->proprietaire} ») ou un superutilisateur ".self::commandeDeLaBase($etat->base);

        if ($portee !== null && self::estLUtc($reglages[$portee])) {
            return 'les options de la connexion le fixent (variable d\'environnement PGOPTIONS, server_options de config/database.php ou options d\'un répartiteur de connexions), avant les réglages du rôle et de la base, qui le fixent à UTC. Retirez-en l\'option timezone.';
        }

        return match ($portee) {
            'role_dans_la_base' => "le réglage du rôle « {$etat->role} » dans cette base (ALTER ROLE … IN DATABASE … SET timezone) le fixe, avant celui de la base. Faites exécuter par ce rôle ou un superutilisateur ALTER ROLE {$role} IN DATABASE {$base} RESET timezone;",
            'role' => "le réglage du rôle « {$etat->role} » (ALTER ROLE … SET timezone) le fixe, avant celui de la base. Faites exécuter par ce rôle ou un superutilisateur ALTER ROLE {$role} RESET timezone;",
            'base' => "le réglage de la base (ALTER DATABASE … SET timezone) le fixe. {$commandeDuProprietaire}",
            null => 'la base n\'a aucun réglage de fuseau, et une session reçoit celui de la configuration du serveur (postgresql.conf) ou des options de la connexion. '
                .'php artisan migrate fixe ce réglage dans une base neuve, mais ne le refait pas une fois ses migrations passées, et une sauvegarde logique restaurée sans --create le perd. '
                .$commandeDuProprietaire,
        };
    }

    /**
     * La commande qui fixe à UTC le fuseau par défaut de la base, à
     * recopier telle quelle.
     */
    private static function commandeDeLaBase(string $base): string
    {
        return 'ALTER DATABASE '.self::identifiant($base).' SET timezone TO \'UTC\';';
    }

    /**
     * Ce nom de fuseau, lu au catalogue ou dans pg_settings, désigne l'UTC.
     */
    private static function estLUtc(?string $nom): bool
    {
        return $nom !== null && in_array(strtolower($nom), self::NOMS_DE_L_UTC, true);
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
