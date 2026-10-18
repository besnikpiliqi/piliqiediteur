<?php

namespace App\Domain\Recherche;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Normalizer;

/**
 * Les recherches de l'utilisateur ignorent la casse et les accents :
 * « creme » trouve « Crème », « ECRAN » trouve « écran ». La base compare
 * la colonne et le terme cherché passés tous deux par la fonction SQL
 * sans_casse_ni_accents(), le même pliage sur les deux moteurs (voir
 * plier()) : sous PostgreSQL, une fonction SQL que crée la migration
 * create_fonction_sans_casse_ni_accents depuis les constantes de cette
 * classe ; sous SQLite, plier() elle-même, enregistrée sur chaque
 * connexion (voir ConnecteurSqlite). Les mêmes tests prouvent donc le
 * comportement sur les deux moteurs, et un test compare les deux
 * fonctions sur toutes les lettres et tous les symboles courants (voir
 * RechercheSansAccentsTest). Les caractères % et _ tapés se cherchent
 * tels quels, échappés par « ! » : PDO lirait une barre oblique inverse
 * dans la requête comme l'échappement d'une apostrophe, et compterait mal
 * ses paramètres.
 *
 * Seule aide des recherches : colonnes des tableaux Filament
 * (rechercheSansAccents(), voir AppServiceProvider, ou dans(), comme les
 * tableaux des produits et des clients d'une vente), listes de choix
 * d'une fiche liée (relationProposable()) et recherches écrites à la main
 * (produits, clients et services de la caisse, nom du client d'un
 * document).
 */
final class RechercheSansAccents
{
    public const FONCTION_SQL = 'sans_casse_ni_accents';

    /**
     * Les blocs Unicode des diacritiques combinants, que la décomposition
     * canonique (NFD) sépare de leur lettre : « é » devient « e » suivi de
     * l'accent aigu U+0301, retiré ensuite.
     *
     * @var list<array{int, int}>
     */
    private const DIACRITIQUES = [
        [0x0300, 0x036F],
        [0x1AB0, 0x1AFF],
        [0x1DC0, 0x1DFF],
        [0x20D0, 0x20FF],
        [0xFE20, 0xFE2F],
    ];

    /**
     * Les lettres que la décomposition canonique ne sépare d'aucun accent
     * (ligatures, lettres barrées), en minuscules, et les apostrophes
     * typographiques des claviers de téléphone.
     *
     * @var array<string, string>
     */
    private const LETTRES_SANS_DECOMPOSITION = [
        'æ' => 'ae',
        'œ' => 'oe',
        'ß' => 'ss',
        'ø' => 'o',
        'đ' => 'd',
        'ð' => 'd',
        'ħ' => 'h',
        'ı' => 'i',
        'ł' => 'l',
        'ŀ' => 'l',
        'ŧ' => 't',
        'þ' => 'th',
        '’' => "'",
        '‘' => "'",
        'ʼ' => "'",
    ];

    /**
     * Restreint la requête aux lignes dont l'une des colonnes contient le
     * terme. Une colonne « relation.colonne » cherche dans la fiche liée
     * (client.nom, factureAchat.fournisseur.nom).
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $requete
     * @param  list<string>  $colonnes
     * @return Builder<TModel>
     */
    public static function appliquer(Builder $requete, string $terme, array $colonnes): Builder
    {
        $motif = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $terme).'%';

        return $requete->where(static function (Builder $requete) use ($colonnes, $motif): void {
            foreach ($colonnes as $colonne) {
                if (str_contains($colonne, '.')) {
                    $requete->orWhereHas(
                        Str::beforeLast($colonne, '.'),
                        static fn (Builder $fiche): Builder => self::contient($fiche, Str::afterLast($colonne, '.'), $motif),
                    );

                    continue;
                }

                self::contient($requete, $colonne, $motif, 'or');
            }
        });
    }

    /**
     * La recherche d'une colonne de tableau Filament :
     * searchable(query: RechercheSansAccents::dans('client.nom')).
     */
    public static function dans(string ...$colonnes): Closure
    {
        return static fn (Builder $query, string $search): Builder => self::appliquer($query, $search, array_values($colonnes));
    }

    /**
     * Le pliage des recherches, celui de sans_casse_ni_accents() sur les
     * deux moteurs : décomposition canonique (NFD), diacritiques retirés,
     * minuscules (correspondance simple d'Unicode, celle de lower() sous
     * PostgreSQL), puis ligatures et lettres barrées remplacées (« Œuvre »
     * devient « oeuvre », « Łódź » devient « lodz »). Tout autre caractère
     * reste tel quel : « ½ », « °C » ou « € » se cherchent comme ils
     * s'écrivent, jamais retirés. Un texte qui n'est pas de l'UTF-8 valide
     * ne correspond à rien.
     */
    public static function plier(?string $texte): ?string
    {
        $decompose = $texte === null ? false : Normalizer::normalize($texte, Normalizer::FORM_D);

        if ($decompose === false) {
            return null;
        }

        $sansDiacritiques = (string) preg_replace('/['.self::classeDesDiacritiques('\x{%04X}').']/u', '', $decompose);

        return strtr(mb_convert_case($sansDiacritiques, MB_CASE_LOWER_SIMPLE, 'UTF-8'), self::LETTRES_SANS_DECOMPOSITION);
    }

    /**
     * La fonction sans_casse_ni_accents() de PostgreSQL, que crée la
     * migration create_fonction_sans_casse_ni_accents : le pliage de
     * plier(), étape par étape (normalize(), regexp_replace(), lower(),
     * replace()), sans aucune extension. lower() suit la collation C.UTF-8
     * de la base (voir CLAUDE.md, Outillage) : sous la collation « C »,
     * elle ne mettrait en minuscules que les lettres ASCII.
     */
    public static function creationDeLaFonctionPostgresql(): string
    {
        $pliage = "lower(regexp_replace(normalize(texte, NFD), '[".self::classeDesDiacritiques('\u%04X')."]', '', 'g'))";

        foreach (self::LETTRES_SANS_DECOMPOSITION as $lettre => $remplacement) {
            $pliage = 'replace('.$pliage.', '.self::litteralSql($lettre).', '.self::litteralSql($remplacement).')';
        }

        return 'CREATE OR REPLACE FUNCTION '.self::FONCTION_SQL.'(texte text) RETURNS text LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$ SELECT '.$pliage.' $$';
    }

    /**
     * Les plages de DIACRITIQUES, chaque code point écrit au format donné
     * (PCRE : \x{0300} ; expressions régulières de PostgreSQL : \u0300).
     */
    private static function classeDesDiacritiques(string $format): string
    {
        return implode('', array_map(
            static fn (array $plage): string => sprintf($format, $plage[0]).'-'.sprintf($format, $plage[1]),
            self::DIACRITIQUES,
        ));
    }

    private static function litteralSql(string $texte): string
    {
        return "'".str_replace("'", "''", $texte)."'";
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $requete
     * @return Builder<TModel>
     */
    private static function contient(Builder $requete, string $colonne, string $motif, string $booleen = 'and'): Builder
    {
        $fonction = self::FONCTION_SQL;
        $colonneSql = $requete->getQuery()->getGrammar()->wrap($requete->qualifyColumn($colonne));

        return $requete->whereRaw("{$fonction}({$colonneSql}) like {$fonction}(?) escape '!'", [$motif], $booleen);
    }
}
