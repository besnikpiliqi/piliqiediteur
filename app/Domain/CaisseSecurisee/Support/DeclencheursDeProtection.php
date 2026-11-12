<?php

namespace App\Domain\CaisseSecurisee\Support;

use App\Domain\Tenancy\Enums\StatutSociete;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Les déclencheurs SQL par lesquels la base refuse elle-même de modifier,
 * de supprimer ou de vider les lignes d'une table, même par une requête
 * qui ne passe pas par le modèle (DB::table(), update(), delete(),
 * truncate() ou forceDelete() du constructeur de requêtes, requête
 * brute). Appelé dans le up() de la migration qui crée la table :
 * - ajoutSeul() : table où l'on ne fait qu'ajouter (art. 286 I-3° bis du
 *   CGI), ni modification, ni suppression, ni vidage ; seule passe, si la
 *   migration le dit, la suppression d'une ligne qui remplit une condition
 *   (un ticket Z que rien ne signe, voir GardesDesClotures) ;
 * - sansSuppression() : table dont aucune ligne ne s'efface ; elle se
 *   modifie, et se marque supprimée (deleted_at) quand son modèle le
 *   prévoit, mais ne se vide pas ;
 * - effacementApresExpiration() : table dont une ligne ne se modifie
 *   jamais et ne s'efface qu'une fois sa colonne expire_le passée (les
 *   traces techniques, voir TraceEvenement) ; elle ne se vide pas. L'heure
 *   de comparaison est celle du serveur de base de données, en UTC, comme
 *   expire_le (voir Instant) ;
 * - effacementApresExpirationOuFinDeLaRelation() : de même, pour une table
 *   qui garde la vérification d'une société (son registre des
 *   vérifications, les entrées de la vérification de son journal d'audit)
 *   : une ligne sans expire_le s'y efface aussi une fois passée la fin de
 *   la conservation des données de vérification que porte sa société,
 *   cinq ans après la fin de la relation (voir FinDeLaRelation) ;
 * - suppressionApresConservation() : table dont une ligne se modifie (son
 *   suivi), mais ne s'efface qu'une fois passée la fin de sa conservation,
 *   une colonne nulle tant qu'elle n'est pas fixée (les codes de courrier,
 *   voir CodeConfirmation) ; elle ne se vide pas. Même heure de
 *   comparaison ;
 * - colonneFigee() : une colonne qui, une fois renseignée, ne s'efface
 *   plus et ne change plus, sauf, si la migration le dit, tant qu'une
 *   condition sur la ligne tient encore (le territoire d'un magasin, voir
 *   Territoire, que la procédure de l'éditeur corrige tant que le magasin
 *   n'a numéroté aucun document) ; si la migration le dit aussi, elle ne
 *   se pose que sur une ligne qui remplit une condition (la fin de la
 *   conservation des données de vérification d'une société, posée
 *   seulement à la fin de la relation), et ne s'efface que tant qu'une
 *   condition sur l'ancienne ligne tient (le lien d'un document à un
 *   ticket Z que rien ne signe, voir GardesDesClotures) ; le reste de la
 *   ligne se modifie.
 *
 * Chaque protection est une fonction plpgsql de refus propre à la table
 * (refus_<table>), appelée avec son message par des déclencheurs BEFORE
 * UPDATE et BEFORE DELETE pour chaque ligne et BEFORE TRUNCATE pour
 * l'instruction ; celle d'une table à effacement après expiration ou après
 * conservation laisse passer la suppression d'une ligne expirée. Le
 * TRUNCATE ... CASCADE de Laravel déclenche aussi le refus des tables
 * protégées qu'il viderait par cascade, et un INSERT ... ON CONFLICT DO
 * UPDATE est une modification, que BEFORE UPDATE refuse.
 *
 * Restent hors de leur portée DROP TABLE et ALTER TABLE ... DISABLE
 * TRIGGER, permis au seul propriétaire de la table : en production, le
 * compte de l'application n'est donc pas celui qui lance les migrations,
 * et il n'a ni UPDATE, ni DELETE, ni TRUNCATE sur les tables en ajout seul
 * (voir CLAUDE.md, étape 15). Sur une table en ajout seul, la signature
 * chaînée des enregistrements révèle de toute façon ce qui aurait été
 * altéré.
 *
 * Les déclencheurs disparaissent avec leur table ; sa fonction de refus se
 * retire par retirer() dans le down() de la migration, après la table, et
 * celle d'une colonne figée par retirerLaColonneFigee().
 *
 * Chaque fonction de garde de la base, ici et dans les autres classes de
 * gardes (GardesDeLaVente, GardesDesDroits…), fixe son chemin de
 * recherche (CHEMIN_DE_RECHERCHE) : elle s'exécute avec les droits de qui
 * écrit, et sans lui PostgreSQL chercherait d'abord les tables
 * temporaires de la session, qu'une table temporaire du même nom qu'une
 * table lue par la garde (users, sequences_documents…) masquerait, lui
 * faisant accepter ce qu'elle refuse. pg_catalog d'abord, les tables de
 * PILIQI ensuite, les tables temporaires en dernier : nommer pg_temp à la
 * fin est la seule façon de ne jamais les lire avant les vraies. Le
 * retrait du droit TEMPORARY au compte de l'application vient en plus, à
 * l'étape 15. Une garde ne verrouille (FOR UPDATE, FOR SHARE) aucune ligne
 * d'une table en ajout seul : le compte de l'application n'aura pas le
 * droit UPDATE que ces verrous exigent.
 */
final class DeclencheursDeProtection
{
    /**
     * La clause de chaque CREATE FUNCTION de garde, entre son langage et
     * son corps.
     *
     * @var literal-string
     */
    public const CHEMIN_DE_RECHERCHE = 'SET search_path = pg_catalog, public, pg_temp';

    /**
     * @param  literal-string  $table
     * @param  literal-string|null  $suppressionPermiseSi  une condition SQL sur la ligne effacée (OLD) sous laquelle sa suppression passe, null pour la refuser toujours
     */
    public static function ajoutSeul(string $table, ?string $suppressionPermiseSi = null): void
    {
        self::poser($table, 'ajout_seul', [
            'UPDATE' => "{$table} : table en ajout seul, modification refusée",
            'DELETE' => "{$table} : table en ajout seul, suppression refusée",
            'TRUNCATE' => "{$table} : table en ajout seul, vidage refusé",
        ], suppressionAussiPermise: $suppressionPermiseSi);
    }

    /**
     * @param  literal-string  $table
     */
    public static function sansSuppression(string $table): void
    {
        self::poser($table, 'sans_suppression', [
            'DELETE' => "{$table} : suppression définitive refusée",
            'TRUNCATE' => "{$table} : vidage refusé",
        ]);
    }

    /**
     * @param  literal-string  $table
     */
    public static function effacementApresExpiration(string $table): void
    {
        self::poser($table, 'effacement_apres_expiration', [
            'UPDATE' => "{$table} : modification refusée",
            'DELETE' => "{$table} : suppression refusée avant expiration",
            'TRUNCATE' => "{$table} : vidage refusé",
        ], colonneDExpiration: 'expire_le');
    }

    /**
     * Comme effacementApresExpiration(), pour une table qui garde la
     * vérification d'une société, chaque ligne avec sa société (societe_id)
     * : une ligne sans expire_le, conservée jusqu'à cinq ans après la fin de
     * la relation avec sa société (Q4), s'efface aussi une fois passée la
     * fin de la conservation que porte sa société
     * (societes.verification_conservee_jusqu_au, posée à la fin de la
     * relation et figée ensuite, voir FinDeLaRelation) ; le journal d'audit
     * ne la donne qu'aux entrées de sa catégorie de la vérification.
     *
     * @param  literal-string  $table
     * @param  literal-string|null  $categorie  la seule catégorie de lignes que la fin de la relation laisse effacer (categorie), null pour toutes
     */
    public static function effacementApresExpirationOuFinDeLaRelation(string $table, ?string $categorie = null): void
    {
        self::poser($table, 'effacement_apres_expiration', [
            'UPDATE' => "{$table} : modification refusée",
            'DELETE' => "{$table} : suppression refusée avant expiration",
            'TRUNCATE' => "{$table} : vidage refusé",
        ], colonneDExpiration: 'expire_le', suppressionAussiPermise: self::finDeLaRelationPassee('OLD', $categorie));
    }

    /**
     * La condition SQL à laquelle le déclencheur d'une table à effacement
     * après expiration ou fin de la relation (voir
     * effacementApresExpirationOuFinDeLaRelation()) laisse aussi effacer
     * une ligne de cette table sans expire_le : la fin de la conservation
     * des données de vérification de sa société passée à l'heure UTC du
     * serveur de base. Qui efface ces lignes les choisit par elle, comme
     * par conditionDExpiration().
     *
     * @param  literal-string  $table
     * @param  literal-string|null  $categorie  la seule catégorie de lignes concernée, null pour toutes
     * @return literal-string
     */
    public static function conditionDeFinDeLaRelation(string $table, ?string $categorie = null): string
    {
        self::verifierLeNom($table);

        return self::finDeLaRelationPassee($table, $categorie);
    }

    /**
     * @param  literal-string  $table
     * @param  literal-string  $colonne  la fin de la conservation d'une ligne, nulle tant qu'elle n'est pas fixée
     */
    public static function suppressionApresConservation(string $table, string $colonne): void
    {
        self::poser($table, 'suppression_apres_conservation', [
            'DELETE' => "{$table} : suppression refusée avant la fin de la conservation",
            'TRUNCATE' => "{$table} : vidage refusé",
        ], colonneDExpiration: $colonne);
    }

    /**
     * Une colonne qui, une fois renseignée, ne s'efface plus et ne change
     * plus : la base refuse toute modification qui l'efface ou lui donne
     * une autre valeur, même par une requête qui ne passe pas par le
     * modèle ; elle laisse poser une valeur sur une colonne nulle, et
     * modifier le reste de la ligne. Une fonction plpgsql propre à la
     * colonne (refus_<table>_<colonne>) et un déclencheur BEFORE UPDATE OF
     * <colonne>.
     *
     * @param  literal-string  $table
     * @param  literal-string  $colonne
     * @param  literal-string|null  $remplacableTantQue  une condition SQL sur l'ancienne ligne (OLD), tant que tient laquelle une valeur renseignée peut encore être remplacée par une autre (jamais effacée) ; null pour ne jamais la remplacer
     * @param  literal-string|null  $poseeSeulementSi  une condition SQL sur la nouvelle ligne (NEW), sans laquelle une valeur ne se pose pas sur la colonne nulle ; null pour la laisser se poser sur toute ligne
     * @param  literal-string|null  $effacableSi  une condition SQL sur l'ancienne ligne (OLD), tant que tient laquelle une valeur renseignée peut encore s'effacer ; null pour ne jamais l'effacer
     */
    public static function colonneFigee(string $table, string $colonne, ?string $remplacableTantQue = null, ?string $poseeSeulementSi = null, ?string $effacableSi = null): void
    {
        self::verifierLeNom($table);
        self::verifierLeNom($colonne);

        $message = "{$table}.{$colonne} : valeur figée, changement refusé";
        $messageDeLaPose = "{$table}.{$colonne} : valeur refusée sur cette ligne";
        $remplacementRefuse = $remplacableTantQue === null ? '1 = 1' : "NOT ({$remplacableTantQue})";
        $effacementRefuse = $effacableSi === null ? '1 = 1' : "NOT ({$effacableSi})";
        $poseRefusee = $poseeSeulementSi === null ? '1 = 0' : "OLD.{$colonne} IS NULL AND NEW.{$colonne} IS NOT NULL AND NOT ({$poseeSeulementSi})";
        $connexion = DB::connection();

        $connexion->unprepared("CREATE OR REPLACE FUNCTION refus_{$table}_{$colonne}() RETURNS trigger LANGUAGE plpgsql ".self::CHEMIN_DE_RECHERCHE.' AS $$ BEGIN '
            ."IF OLD.{$colonne} IS NOT NULL AND ((NEW.{$colonne} IS NULL AND {$effacementRefuse}) OR (NEW.{$colonne} IS NOT NULL AND NEW.{$colonne} IS DISTINCT FROM OLD.{$colonne} AND {$remplacementRefuse})) "
            ."THEN RAISE EXCEPTION '%', TG_ARGV[0]; END IF; "
            ."IF {$poseRefusee} THEN RAISE EXCEPTION '%', TG_ARGV[1]; END IF; RETURN NEW; END \$\$");
        $connexion->unprepared("CREATE TRIGGER {$table}_{$colonne}_figee BEFORE UPDATE OF {$colonne} ON {$table} FOR EACH ROW EXECUTE FUNCTION refus_{$table}_{$colonne}('{$message}', '{$messageDeLaPose}')");
    }

    /**
     * Une colonne entière qui ne descend jamais sous $minimum : une
     * quantité d'articles (au moins 1), un compteur, une taille ou un délai
     * (au moins 0) ; unsignedInteger() ne contraint rien sous PostgreSQL.
     * Une valeur nulle reste permise là où la colonne l'est. La contrainte
     * disparaît avec sa table.
     *
     * @param  literal-string  $table
     * @param  literal-string  $colonne
     */
    public static function auMoins(string $table, string $colonne, int $minimum): void
    {
        self::verifierLeNom($table);
        self::verifierLeNom($colonne);

        DB::connection()->unprepared("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$colonne}_au_moins_{$minimum} CHECK ({$colonne} >= {$minimum})");
    }

    /**
     * Retire la fonction de refus d'une colonne figée (voir colonneFigee())
     * une fois sa table supprimée.
     *
     * @param  literal-string  $table
     * @param  literal-string  $colonne
     */
    public static function retirerLaColonneFigee(string $table, string $colonne): void
    {
        self::verifierLeNom($table);
        self::verifierLeNom($colonne);

        DB::connection()->unprepared("DROP FUNCTION IF EXISTS refus_{$table}_{$colonne}()");
    }

    /**
     * La condition SQL à laquelle le déclencheur d'une table à effacement
     * après expiration, ou après conservation pour la colonne de sa fin de
     * conservation, laisse effacer une ligne : cette colonne (expire_le)
     * passée à l'heure UTC du serveur de base. Qui efface ces lignes (voir
     * app:purger-traces) les choisit par elle : l'heure de l'application,
     * que peut devancer celle du serveur de base, ne ferait refuser tout un
     * lot pour une seule ligne.
     *
     * @param  literal-string  $colonne
     * @return literal-string
     */
    public static function conditionDExpiration(string $colonne = 'expire_le'): string
    {
        self::verifierLeNom($colonne);

        return self::ligneExpiree($colonne);
    }

    /**
     * Retire ce qui survit à la table protégée, une fois celle-ci
     * supprimée : sa fonction de refus. Sans CASCADE : tant que la table
     * existe, ses déclencheurs dépendent de la fonction et PostgreSQL
     * refuse de la retirer.
     *
     * @param  literal-string  $table
     */
    public static function retirer(string $table): void
    {
        self::verifierLeNom($table);

        DB::connection()->unprepared("DROP FUNCTION IF EXISTS refus_{$table}()");
    }

    /**
     * La fonction de refus de la table, et un déclencheur par opération
     * refusée : BEFORE UPDATE et BEFORE DELETE pour chaque ligne, BEFORE
     * TRUNCATE pour l'instruction. Pour une table à effacement après
     * expiration ou après conservation, la fonction laisse passer la
     * suppression d'une ligne dont la colonne d'expiration, en UTC, est
     * passée à l'heure UTC du serveur, ou qui remplit l'autre condition de
     * sa protection ; pour une table en ajout seul, celle d'une ligne qui
     * remplit sa condition, s'il y en a une.
     *
     * @param  literal-string  $table
     * @param  literal-string  $protection  le suffixe du nom des déclencheurs
     * @param  array<'UPDATE'|'DELETE'|'TRUNCATE', literal-string>  $refus  le message de refus de chaque opération
     * @param  literal-string|null  $colonneDExpiration  la colonne au-delà de laquelle la suppression d'une ligne passe, null pour la refuser toujours
     * @param  literal-string|null  $suppressionAussiPermise  une autre condition SQL sur la ligne effacée (OLD) sous laquelle sa suppression passe aussi
     */
    private static function poser(string $table, string $protection, array $refus, ?string $colonneDExpiration = null, ?string $suppressionAussiPermise = null): void
    {
        self::verifierLeNom($table);

        if ($colonneDExpiration !== null) {
            self::verifierLeNom($colonneDExpiration);
        }

        $suppressionPermise = implode(' OR ', array_filter([
            $colonneDExpiration === null ? null : '('.self::ligneExpiree("OLD.{$colonneDExpiration}").')',
            $suppressionAussiPermise === null ? null : "({$suppressionAussiPermise})",
        ]));
        $ligneExpireeEffacee = $suppressionPermise === ''
            ? ''
            : "IF TG_OP = 'DELETE' THEN IF {$suppressionPermise} THEN RETURN OLD; END IF; END IF; ";
        $connexion = DB::connection();

        $connexion->unprepared("CREATE OR REPLACE FUNCTION refus_{$table}() RETURNS trigger LANGUAGE plpgsql ".self::CHEMIN_DE_RECHERCHE." AS \$\$ BEGIN {$ligneExpireeEffacee}RAISE EXCEPTION '%', TG_ARGV[0]; END \$\$");

        foreach ($refus as $operation => $message) {
            $portee = $operation === 'TRUNCATE' ? 'STATEMENT' : 'ROW';

            $connexion->unprepared("CREATE TRIGGER {$table}_{$protection}_".strtolower($operation)
                ." BEFORE {$operation} ON {$table} FOR EACH {$portee} EXECUTE FUNCTION refus_{$table}('{$message}')");
        }
    }

    /**
     * Une ligne expirée : sa colonne d'expiration, un horodatage sans
     * fuseau écrit en UTC (voir Instant), renseignée et passée à l'heure
     * UTC du serveur de base (now() AT TIME ZONE 'UTC').
     *
     * @param  literal-string  $colonne  la colonne (expire_le), ou celle de la ligne qu'un déclencheur efface (OLD.expire_le)
     * @return literal-string
     */
    private static function ligneExpiree(string $colonne): string
    {
        return "{$colonne} IS NOT NULL AND {$colonne} <= (now() AT TIME ZONE 'UTC')";
    }

    /**
     * La condition SQL sur la nouvelle ligne d'une société (NEW) d'une
     * relation avec l'éditeur qui a pris fin : société révoquée, ou
     * résiliée (étape 12). Seule une telle société reçoit la fin de la
     * conservation de ses données de vérification (voir FinDeLaRelation),
     * et seules ses données s'effacent une fois cette fin passée.
     *
     * @param  literal-string  $ligne
     * @return literal-string
     */
    public static function relationFinie(string $ligne = 'NEW'): string
    {
        self::verifierLeNom(strtolower($ligne));

        return "{$ligne}.statut IN ('".StatutSociete::Revoquee->value."', '".StatutSociete::Resiliee->value."')";
    }

    /**
     * Une ligne de la vérification d'une société dont la conservation a
     * pris fin avec la relation : sans expire_le, de la catégorie donnée
     * s'il y en a une, sa société, révoquée ou résiliée, ayant passé, à
     * l'heure UTC du serveur de base, la fin de la conservation de ses
     * données de vérification.
     *
     * @param  literal-string  $ligne  la ligne (OLD dans un déclencheur, le nom de sa table dans une requête)
     * @param  literal-string|null  $categorie
     * @return literal-string
     */
    private static function finDeLaRelationPassee(string $ligne, ?string $categorie): string
    {
        if ($categorie !== null && preg_match('/^[a-z_]+$/D', $categorie) !== 1) {
            throw new InvalidArgumentException("Catégorie invalide pour un déclencheur : {$categorie}.");
        }

        return implode(' AND ', array_filter([
            "{$ligne}.expire_le IS NULL",
            $categorie === null ? null : "{$ligne}.categorie = '{$categorie}'",
            'EXISTS (SELECT 1 FROM societes WHERE societes.id = '.$ligne.'.societe_id AND '.self::relationFinie('societes').' AND '.self::ligneExpiree('societes.verification_conservee_jusqu_au').')',
        ]));
    }

    private static function verifierLeNom(string $table): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/D', $table) !== 1) {
            throw new InvalidArgumentException("Nom de table invalide pour un déclencheur : {$table}.");
        }
    }
}
