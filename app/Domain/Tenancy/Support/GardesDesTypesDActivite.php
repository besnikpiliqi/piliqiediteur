<?php

namespace App\Domain\Tenancy\Support;

use App\Domain\Authorization\PermissionRegistry;
use App\Domain\CaisseSecurisee\Support\DeclencheursDeProtection;
use App\Domain\Tenancy\Enums\TypeDActivite;
use Illuminate\Support\Facades\DB;

/**
 * Les gardes par lesquelles la base elle-même tient le type d'activité
 * d'une société (voir TypeDActivite), même écrit par DB::table(), une
 * requête brute ou une action défectueuse :
 * - societes_type_d_activite_connu_et_fixe (societes) : un type hors de
 *   ceux de l'énumération, et toute modification du type, d'aucun type
 *   vers aucun autre ;
 * - magasins_jamais_dans_un_cabinet (magasins) : un magasin, démonstration
 *   comprise, dans une société de type cabinet d'expertise comptable ;
 * - autorisations_d_articles_seulement_avec_des_articles
 *   (autorisations_affectations) : une case de la famille des articles
 *   (lue au référentiel des autorisations de magasin, voir
 *   PermissionRegistry::articles()) sur une affectation d'une société qui
 *   n'a pas d'articles (voir TypeDActivite::aDesArticles()), qu'elle vise
 *   un magasin ou tous ;
 * - produits_seulement_avec_des_articles (produits),
 *   factures_achat_seulement_avec_des_articles (factures_achat),
 *   avoirs_fournisseur_seulement_avec_des_articles (avoirs_fournisseur),
 *   inventaires_ouverture_seulement_avec_des_articles
 *   (inventaires_ouverture) : une ligne dans un magasin dont la société
 *   n'a pas d'articles.
 * Les fournisseurs n'ont pas de garde : les factures reçues de l'étape 2
 * et la comptabilité de l'étape 5 en auront besoin dans tous les types.
 *
 * Chacune se pose dans la migration de création de sa table, et se retire
 * avant elle, fonction comprise, par retirer(). Le type d'une société ne
 * changeant jamais, une garde qui le lit par la société d'une ligne ne
 * verrouille rien : aucune écriture simultanée ne peut le rendre faux.
 *
 * Chaque garde est une fonction plpgsql et son déclencheur BEFORE INSERT
 * OR UPDATE OF les colonnes qu'elle lit.
 */
final class GardesDesTypesDActivite
{
    public const TYPE_FIXE = 'societes_type_d_activite_connu_et_fixe';

    public const MAGASINS = 'magasins_jamais_dans_un_cabinet';

    public const CASES_D_ARTICLES = 'autorisations_d_articles_seulement_avec_des_articles';

    public const PRODUITS = 'produits_seulement_avec_des_articles';

    public const FACTURES_ACHAT = 'factures_achat_seulement_avec_des_articles';

    public const AVOIRS_FOURNISSEUR = 'avoirs_fournisseur_seulement_avec_des_articles';

    public const INVENTAIRES_OUVERTURE = 'inventaires_ouverture_seulement_avec_des_articles';

    /**
     * La table que protège chaque garde, et les colonnes dont la
     * modification la déclenche, séparées par des virgules.
     */
    private const TABLES = [
        self::TYPE_FIXE => ['societes', 'type_activite'],
        self::MAGASINS => ['magasins', 'societe_id'],
        self::CASES_D_ARTICLES => ['autorisations_affectations', 'affectation_id, autorisation'],
        self::PRODUITS => ['produits', 'magasin_id'],
        self::FACTURES_ACHAT => ['factures_achat', 'magasin_id'],
        self::AVOIRS_FOURNISSEUR => ['avoirs_fournisseur', 'magasin_id'],
        self::INVENTAIRES_OUVERTURE => ['inventaires_ouverture', 'magasin_id'],
    ];

    /**
     * @param  key-of<self::TABLES>  $garde
     */
    public static function poser(string $garde): void
    {
        [$table, $colonnes] = self::TABLES[$garde];
        $controles = implode(' ', array_map(
            static fn (array $un): string => "IF {$un[0]} THEN RAISE EXCEPTION '%', ".self::message($garde, $un[1]).'; END IF;',
            self::refus($garde),
        ));
        $connexion = DB::connection();

        $connexion->unprepared("CREATE OR REPLACE FUNCTION garde_{$garde}() RETURNS trigger LANGUAGE plpgsql ".DeclencheursDeProtection::CHEMIN_DE_RECHERCHE." AS \$\$ BEGIN {$controles} RETURN NEW; END \$\$");
        $connexion->unprepared("CREATE TRIGGER {$garde} BEFORE INSERT OR UPDATE OF {$colonnes} ON {$table} FOR EACH ROW EXECUTE FUNCTION garde_{$garde}()");
    }

    /**
     * Retire le déclencheur de la garde puis sa fonction, que le
     * déclencheur empêcherait sinon de retirer : dans le down() de la
     * migration, avant sa table.
     *
     * @param  key-of<self::TABLES>  $garde
     */
    public static function retirer(string $garde): void
    {
        [$table] = self::TABLES[$garde];
        $connexion = DB::connection();

        $connexion->unprepared("DROP TRIGGER IF EXISTS {$garde} ON {$table}");
        $connexion->unprepared("DROP FUNCTION IF EXISTS garde_{$garde}()");
    }

    /**
     * Les refus de chaque garde : la condition qui refuse, en SQL, et son
     * message. NEW désigne la ligne écrite, OLD la ligne d'avant une
     * modification, nulle à l'insertion : une condition qui compare à OLD
     * ne refuse donc rien à l'insertion.
     *
     * @param  key-of<self::TABLES>  $garde
     * @return list<array{0: literal-string, 1: literal-string}>
     */
    private static function refus(string $garde): array
    {
        $cabinet = "'".TypeDActivite::Cabinet->value."'";
        $avecDesArticles = self::liste(array_filter(TypeDActivite::cases(), static fn (TypeDActivite $type): bool => $type->aDesArticles()));
        $sansArticlesDansLeMagasin = "COALESCE((SELECT societes.type_activite FROM magasins INNER JOIN societes ON societes.id = magasins.societe_id WHERE magasins.id = NEW.magasin_id), '') NOT IN ({$avecDesArticles})";

        return match ($garde) {
            self::TYPE_FIXE => [
                ['NEW.type_activite NOT IN ('.self::liste(TypeDActivite::cases()).')', 'type d\'activité inconnu'],
                ['NEW.type_activite <> OLD.type_activite', 'le type d\'activité d\'une société ne change jamais'],
            ],
            self::MAGASINS => [
                ["COALESCE((SELECT type_activite FROM societes WHERE id = NEW.societe_id), '') = {$cabinet}", 'un cabinet d\'expertise comptable n\'a aucun magasin'],
            ],
            self::CASES_D_ARTICLES => [
                [
                    "COALESCE((SELECT famille FROM referentiel_autorisations_magasin WHERE cle = NEW.autorisation), '') = '".PermissionRegistry::FAMILLE_ARTICLES."'"
                        ." AND COALESCE((SELECT societes.type_activite FROM affectations INNER JOIN societes ON societes.id = affectations.societe_id WHERE affectations.id = NEW.affectation_id), '') NOT IN ({$avecDesArticles})",
                    'une autorisation de la famille des articles n\'existe pas dans une société sans articles',
                ],
            ],
            self::PRODUITS => [[$sansArticlesDansLeMagasin, 'une société sans articles n\'a pas de produit']],
            self::FACTURES_ACHAT => [[$sansArticlesDansLeMagasin, 'une société sans articles n\'a pas de facture d\'achat']],
            self::AVOIRS_FOURNISSEUR => [[$sansArticlesDansLeMagasin, 'une société sans articles n\'a pas d\'avoir fournisseur']],
            self::INVENTAIRES_OUVERTURE => [[$sansArticlesDansLeMagasin, 'une société sans articles n\'a pas d\'inventaire d\'ouverture']],
        };
    }

    /**
     * Les valeurs de ces types, en liste SQL.
     *
     * @param  array<array-key, TypeDActivite>  $types
     * @return literal-string
     */
    private static function liste(array $types): string
    {
        return implode(', ', array_map(static fn (TypeDActivite $type): string => "'{$type->value}'", $types));
    }

    /**
     * Le message d'un refus, préfixé du nom de sa garde, en littéral SQL
     * (voir LitteralSql).
     *
     * @param  key-of<self::TABLES>  $garde
     * @param  literal-string  $raison
     * @return literal-string
     */
    private static function message(string $garde, string $raison): string
    {
        return LitteralSql::de("{$garde} : {$raison}");
    }
}
