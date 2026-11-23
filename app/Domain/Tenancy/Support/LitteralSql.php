<?php

namespace App\Domain\Tenancy\Support;

use InvalidArgumentException;

/**
 * Un texte du code écrit en littéral SQL de PostgreSQL, entre dollars
 * (« $texte$…$texte$ ») : sans échappement, il garde tels quels ses
 * apostrophes et ses barres obliques inverses, et se place aussi bien
 * dans une requête que dans le corps d'une fonction, lui-même entre
 * dollars (« $$…$$ »), ou parmi les arguments d'un déclencheur. Il
 * n'est pas vide et ne contient aucun dollar : l'un et l'autre pourraient
 * le terminer, ou terminer le corps de la fonction qui le contient
 * (« $texte$$texte$ » contient « $$ »). Seul un texte écrit dans le code s'y
 * place (literal-string), jamais une saisie : l'instruction qui le reçoit
 * reste faite de textes du code, ce que vérifie l'analyse statique
 * (DB::unprepared() n'accepte rien d'autre). Les gardes de la base y
 * écrivent leurs messages de refus, et la fonction de la recherche sans
 * accents ses lettres.
 */
final class LitteralSql
{
    public const REFUS_DOLLAR = 'Un littéral SQL entre dollars ne contient aucun dollar.';

    public const REFUS_VIDE = 'Un littéral SQL entre dollars n\'est jamais vide.';

    private const DOLLARS = '$texte$';

    /**
     * @param  literal-string  $texte
     * @return literal-string
     *
     * @throws InvalidArgumentException un texte vide, ou qui contient un dollar
     */
    public static function de(string $texte): string
    {
        if ($texte === '') {
            throw new InvalidArgumentException(self::REFUS_VIDE);
        }

        if (str_contains($texte, '$')) {
            throw new InvalidArgumentException(self::REFUS_DOLLAR);
        }

        return self::DOLLARS.$texte.self::DOLLARS;
    }
}
