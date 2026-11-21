<?php

namespace App\Domain\Demonstration\Bases;

use App\Domain\Demonstration\SiteDePiliqi;
use App\Domain\Tenancy\Support\ValeurSimple;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Les deux rôles PostgreSQL du site de démonstration (lot DEMO-COPIE,
 * choix 33) : demo_admin (CREATEDB, membre de pg_signal_backend),
 * propriétaire du registre, des modèles et des copies, seul à créer, copier
 * et supprimer une base, utilisé par les commandes demonstration:* et le
 * travailleur de la file demonstration-admin ; demo_app (sans CREATEDB,
 * propriétaire de rien), pour les pages et les travaux des copies. Le site
 * réel garde son seul rôle.
 *
 * Dans une base modèle, et donc dans chacune de ses copies, demo_app lit et
 * écrit les tables et leurs séquences, sauf les privations du réel à
 * l'étape 15 : ni UPDATE, ni DELETE, ni TRUNCATE sur une table en ajout
 * seul (celles que gardent les déclencheurs *_ajout_seul_*). Dans le
 * registre, il lit tout et n'écrit que les témoins, les nonces des
 * messages reçus, les travaux et le cache ; une demande de copie, il
 * l'inscrit par la seule fonction inscrire_une_copie() (voir
 * FONCTION_D_INSCRIPTION), dès la réception du message « creer ».
 */
final class RolesDeLaDemonstration
{
    /**
     * Les tables du registre que le rôle des pages écrit.
     */
    public const TABLES_ECRITES_PAR_LES_PAGES = ['temoins', 'messages_recus', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks'];

    /**
     * La seule écriture des copies et des liens que le rôle des pages
     * peut faire (C.3, C.4) : inscrire une demande reçue du site réel, en
     * préparation, sans base, avec son lien, une seule fois par
     * référence, dès la réception du message « creer », pour qu'un lien
     * tout neuf ou le bouton du propriétaire mène à la page d'attente
     * avant même le passage du travail de création. La fonction appartient
     * à demo_admin, propriétaire du registre (SECURITY DEFINER, chemin de
     * recherche fixé) ; personne d'autre que le rôle des pages ne
     * l'exécute ; elle ne change ni ne supprime jamais une copie.
     */
    public const FONCTION_D_INSCRIPTION = <<<'SQL'
        create or replace function public.inscrire_une_copie(
            p_reference text, p_repere text, p_type text, p_validee boolean,
            p_empreinte text, p_generation integer, p_fin timestamp, p_maintenant timestamp
        ) returns void
        language plpgsql
        security definer
        set search_path = pg_catalog, public
        as $$
        declare
            v_copie bigint;
        begin
            insert into public.copies (reference, repere, type, validee, creee_le, fin, generation_du_lien, etat)
            values (p_reference, p_repere, p_type, p_validee, p_maintenant, p_fin, p_generation, 'en_preparation')
            on conflict (reference) do nothing
            returning id into v_copie;

            if v_copie is not null then
                insert into public.liens (copie_id, empreinte, generation, fin, cree_le)
                values (v_copie, p_empreinte, p_generation, p_fin, p_maintenant);
            end if;
        end
        $$
        SQL;

    public const SIGNATURE_DE_LA_FONCTION_D_INSCRIPTION = 'public.inscrire_une_copie(text, text, text, boolean, text, integer, timestamp, timestamp)';

    public const MESSAGE_ROLE_INCONNU = 'Le rôle des pages du site de démonstration n\'est pas réglé (DB_REGISTRE_USERNAME, demo_app) : voir le README.';

    /**
     * Le rôle des pages et des travaux des copies (demo_app), qui se
     * connecte au registre (DB_REGISTRE_USERNAME) et à chaque copie.
     */
    public static function roleDesPages(): string
    {
        $role = ValeurSimple::enTexte(config('database.connections.registre.username'));

        if ($role === '') {
            throw new RuntimeException(self::MESSAGE_ROLE_INCONNU);
        }

        return $role;
    }

    /**
     * Le rôle qui crée, copie et supprime les bases (demo_admin).
     */
    public static function roleDeLAdministration(): string
    {
        return ValeurSimple::enTexte(config('database.connections.demo_admin.username'));
    }

    /**
     * Seul le rôle des pages se connecte à la base, en plus de son
     * propriétaire : le droit de connexion de PUBLIC est retiré.
     */
    public static function reglerLaConnexionALaBase(Connection $connexion): void
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $role = IdentifiantSql::de(self::roleDesPages());
        $connexion->unprepared("do \$\$ begin execute format('revoke all on database %I from public', current_database()); execute format('grant connect on database %I to {$role}', current_database()); end \$\$");
    }

    /**
     * Les droits du rôle des pages sur les tables et les séquences d'une
     * base modèle, après ses migrations (C.2, étape 2) : copiés avec elle
     * dans chaque copie, puisqu'ils appartiennent au schéma.
     */
    public static function accorderLesDroitsDUneBase(ConnectionInterface $connexion): void
    {
        SiteDePiliqi::exiger(SiteDePiliqi::Demonstration);
        $role = IdentifiantSql::de(self::roleDesPages());
        $connexion->unprepared("grant usage on schema public to {$role}");
        $connexion->unprepared("grant select, insert, update, delete on all tables in schema public to {$role}");
        $connexion->unprepared("grant usage, select, update on all sequences in schema public to {$role}");

        foreach (self::tablesEnAjoutSeul($connexion) as $table) {
            $connexion->unprepared('revoke update, delete, truncate on '.IdentifiantSql::de($table)." from {$role}");
        }
    }

    /**
     * Les tables en ajout seul d'une base : celles que garde un déclencheur
     * *_ajout_seul_* (voir DeclencheursDeProtection::ajoutSeul()).
     *
     * @return list<string>
     */
    public static function tablesEnAjoutSeul(ConnectionInterface $connexion): array
    {
        return array_values(array_map(
            static fn (mixed $table): string => ValeurSimple::enTexte($table),
            $connexion->table('pg_trigger')
                ->join('pg_class', 'pg_class.oid', '=', 'pg_trigger.tgrelid')
                ->join('pg_namespace', 'pg_namespace.oid', '=', 'pg_class.relnamespace')
                ->where('pg_namespace.nspname', 'public')
                ->where('pg_trigger.tgname', 'like', '%\_ajout\_seul\_%')
                ->distinct()
                ->orderBy('pg_class.relname')
                ->pluck('pg_class.relname')
                ->all(),
        ));
    }

    /**
     * Les droits du rôle des pages sur le registre (C.1).
     */
    public static function accorderLesDroitsDuRegistre(ConnectionInterface $connexion): void
    {
        $role = IdentifiantSql::de(self::roleDesPages());
        $connexion->unprepared("grant usage on schema public to {$role}");
        $connexion->unprepared("grant select on all tables in schema public to {$role}");

        foreach (self::TABLES_ECRITES_PAR_LES_PAGES as $table) {
            $connexion->unprepared("grant insert, update, delete on {$table} to {$role}");
        }

        $connexion->unprepared("grant usage, select on all sequences in schema public to {$role}");
        $connexion->unprepared(self::FONCTION_D_INSCRIPTION);
        $connexion->unprepared('revoke all on function '.self::SIGNATURE_DE_LA_FONCTION_D_INSCRIPTION.' from public');
        $connexion->unprepared('grant execute on function '.self::SIGNATURE_DE_LA_FONCTION_D_INSCRIPTION." to {$role}");
    }

    public static function retirerLesDroitsDuRegistre(ConnectionInterface $connexion): void
    {
        $role = IdentifiantSql::de(self::roleDesPages());
        $connexion->unprepared('drop function if exists '.self::SIGNATURE_DE_LA_FONCTION_D_INSCRIPTION);
        $connexion->unprepared("revoke all on all tables in schema public from {$role}");
        $connexion->unprepared("revoke all on all sequences in schema public from {$role}");
    }
}
