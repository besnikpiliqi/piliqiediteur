<?php

namespace App\Domain\Recherche;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Les recherches de l'utilisateur ignorent la casse et les accents :
 * « creme » trouve « Crème », « ECRAN » trouve « écran ». La base compare
 * la colonne et le terme cherché passés tous deux par la fonction SQL
 * sans_casse_ni_accents() : sous PostgreSQL, lower(unaccent()), que crée
 * la migration create_fonction_sans_casse_ni_accents ; sous SQLite,
 * plier(), enregistrée sur chaque connexion (voir ConnecteurSqlite). Les
 * mêmes tests prouvent donc le comportement sur les deux moteurs. Les
 * caractères % et _ tapés se cherchent tels quels, échappés par « ! » :
 * PDO lirait une barre oblique inverse dans la requête comme
 * l'échappement d'une apostrophe, et compterait mal ses paramètres.
 *
 * Seule aide des recherches : colonnes des tableaux Filament
 * (rechercheSansAccents(), voir AppServiceProvider), listes de choix
 * d'une fiche liée (relationProposable()) et recherches écrites à la main
 * (produits, clients de la caisse, nom du client d'un document).
 */
final class RechercheSansAccents
{
    public const FONCTION_SQL = 'sans_casse_ni_accents';

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
     * La fonction sans_casse_ni_accents() de SQLite : en minuscules, sans
     * accents ni ligatures (« Œuvre » devient « oeuvre »), comme
     * lower(unaccent()) sous PostgreSQL pour l'alphabet latin. Les autres
     * écritures sont translittérées ou retirées : SQLite ne sert qu'au
     * développement et aux tests.
     */
    public static function plier(?string $texte): ?string
    {
        return $texte === null ? null : mb_strtolower(Str::ascii($texte));
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
