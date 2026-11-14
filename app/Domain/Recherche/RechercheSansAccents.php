<?php

namespace App\Domain\Recherche;

use App\Domain\Tenancy\Support\ExpressionDUneColonne;
use App\Domain\Tenancy\Support\LitteralSql;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Les recherches de l'utilisateur ignorent la casse et les accents :
 * « creme » trouve « Crème », « ECRAN » trouve « écran ». La base compare
 * la colonne et le terme cherché passés tous deux par la fonction SQL
 * sans_casse_ni_accents(), que crée la migration
 * create_fonction_sans_casse_ni_accents depuis les constantes de cette
 * classe (voir creationDeLaFonction()) ; un test compare son pliage, sur
 * toutes les lettres et tous les symboles courants, à celui que définit
 * Unicode (voir RechercheSansAccentsTest). Les caractères % et _ tapés se
 * cherchent tels quels, échappés par « ! » : PDO lirait une barre oblique
 * inverse dans la requête comme l'échappement d'une apostrophe, et
 * compterait mal ses paramètres.
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
    public const DIACRITIQUES = [
        [0x0300, 0x036F],
        [0x1AB0, 0x1AFF],
        [0x1DC0, 0x1DFF],
        [0x20D0, 0x20FF],
        [0xFE20, 0xFE2F],
    ];

    /**
     * Les lettres que la décomposition canonique ne sépare d'aucun accent
     * (ligatures, lettres barrées), en minuscules, et les apostrophes
     * typographiques des claviers de téléphone, chacune avec ce qui la
     * remplace une fois le texte en minuscules.
     *
     * @var array<literal-string, literal-string>
     */
    public const LETTRES_SANS_DECOMPOSITION = [
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
     * La fonction sans_casse_ni_accents(), que crée la migration
     * create_fonction_sans_casse_ni_accents, sans aucune extension :
     * décomposition canonique (normalize(texte, NFD)), diacritiques retirés
     * (regexp_replace()), minuscules (lower(), la correspondance simple
     * d'Unicode sous la collation C.UTF-8 de la base, voir CLAUDE.md,
     * Outillage : sous la collation « C », elle ne mettrait en minuscules
     * que les lettres ASCII), puis ligatures et lettres barrées remplacées
     * (replace()) : « Œuvre » devient « oeuvre », « Łódź » devient « lodz ».
     * Tout autre caractère reste tel quel : « ½ », « °C » ou « € » se
     * cherchent comme ils s'écrivent, jamais retirés.
     *
     * @return literal-string
     */
    public static function creationDeLaFonction(): string
    {
        $diacritiques = implode('', array_map(
            static fn (array $plage): string => sprintf('\\u%04X-\\u%04X', $plage[0], $plage[1]),
            self::DIACRITIQUES,
        ));
        $pliage = "lower(regexp_replace(normalize(texte, NFD), '[{$diacritiques}]', '', 'g'))";

        foreach (self::LETTRES_SANS_DECOMPOSITION as $lettre => $remplacement) {
            $pliage = 'replace('.$pliage.', '.LitteralSql::de($lettre).', '.LitteralSql::de($remplacement).')';
        }

        return 'CREATE OR REPLACE FUNCTION '.self::FONCTION_SQL.'(texte text) RETURNS text LANGUAGE sql IMMUTABLE STRICT PARALLEL SAFE AS $$ SELECT '.$pliage.' $$';
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $requete
     * @return Builder<TModel>
     */
    private static function contient(Builder $requete, string $colonne, string $motif, string $booleen = 'and'): Builder
    {
        return $requete->whereRaw(
            new ExpressionDUneColonne(self::FONCTION_SQL.'('.ExpressionDUneColonne::COLONNE.') like '.self::FONCTION_SQL."(?) escape '!'", $requete->qualifyColumn($colonne)),
            [$motif],
            $booleen,
        );
    }
}
