<?php

namespace App\Domain\Demonstration;

use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use App\Domain\Demonstration\Exceptions\SiteMalConfigureException;
use App\Domain\Tenancy\Support\LitteralSql;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * La marque d'une base de PILIQI (lot DEMO-COPIE, A.5) : la table
 * site_de_la_base, d'une seule ligne, écrite par sa migration de création
 * avec le site que sert le serveur qui migre (config piliqi.site) : « reel »
 * sur le site réel, « demonstration » dans une base modèle du site de
 * démonstration, donc dans chacune de ses copies.
 *
 * La base elle-même garde la marque : une garde refuse toute modification,
 * toute suppression, tout vidage et toute seconde ligne. Le site réel
 * refuse de servir une base marquée « demonstration », le site de
 * démonstration une base qui ne l'est pas (voir exigerPour()) : une erreur
 * de configuration, un site pointé sur la base de l'autre, est refusée au
 * lieu d'écrire des données fictives dans le réel ou de vraies données
 * dans une copie.
 */
final class MarqueDeLaBase
{
    public const TABLE = 'site_de_la_base';

    public const MESSAGE_GARDE = 'site_de_la_base : la marque d\'une base s\'écrit une seule fois, à sa création, et ne change jamais';

    public const MESSAGE_AUTRE_MARQUE = 'La base « %s » est marquée « %s » : le %s ne la sert pas. Vérifiez DB_DATABASE dans le .env de ce serveur.';

    public const MESSAGE_SANS_MARQUE = 'La base « %s » n\'a pas de marque (table site_de_la_base) : migrez-la par le site qui la sert (php artisan migrate).';

    /**
     * Écrit la marque du site servi dans la table que sa migration de
     * création vient de créer, et pose la garde.
     */
    public static function poser(): void
    {
        $connexion = DB::connection();
        $connexion->table(self::TABLE)->insert(['site' => SiteDePiliqi::courant()->value]);
        $connexion->unprepared('CREATE OR REPLACE FUNCTION garde_site_de_la_base() RETURNS trigger LANGUAGE plpgsql '.DeclencheursDeProtection::CHEMIN_DE_RECHERCHE.' AS $$ BEGIN '
            ."RAISE EXCEPTION '%', ".LitteralSql::de(self::MESSAGE_GARDE).'; END $$');
        $connexion->unprepared('CREATE TRIGGER garde_site_de_la_base BEFORE INSERT OR UPDATE OR DELETE ON '.self::TABLE.' FOR EACH ROW EXECUTE FUNCTION garde_site_de_la_base()');
        $connexion->unprepared('CREATE TRIGGER garde_site_de_la_base_vidage BEFORE TRUNCATE ON '.self::TABLE.' FOR EACH STATEMENT EXECUTE FUNCTION garde_site_de_la_base()');
    }

    /**
     * Retire la fonction de la garde, une fois la table supprimée par le
     * retour arrière de sa migration.
     */
    public static function retirerLaGarde(): void
    {
        DB::connection()->unprepared('DROP FUNCTION IF EXISTS garde_site_de_la_base()');
    }

    /**
     * Le site dont une base porte la marque, ou null quand elle n'en a pas
     * (une base pas encore migrée, la base sentinelle du site de
     * démonstration).
     */
    public static function lire(ConnectionInterface $connexion): ?SiteDePiliqi
    {
        if ($connexion->scalar('SELECT to_regclass(?) IS NOT NULL', ['public.'.self::TABLE]) !== true) {
            return null;
        }

        $site = $connexion->table(self::TABLE)->value('site');

        return is_string($site) ? SiteDePiliqi::tryFrom($site) : null;
    }

    /**
     * Arrête le site qui s'apprête à servir une base qui n'est pas la
     * sienne.
     *
     * @throws SiteMalConfigureException
     */
    public static function exigerPour(SiteDePiliqi $site, ConnectionInterface $connexion): void
    {
        $marque = self::lire($connexion);
        $base = $connexion->getDatabaseName();

        if ($marque === null) {
            throw new SiteMalConfigureException(sprintf(self::MESSAGE_SANS_MARQUE, $base));
        }

        if ($marque !== $site) {
            throw new SiteMalConfigureException(sprintf(self::MESSAGE_AUTRE_MARQUE, $base, $marque->value, $site->libelle()));
        }
    }
}
