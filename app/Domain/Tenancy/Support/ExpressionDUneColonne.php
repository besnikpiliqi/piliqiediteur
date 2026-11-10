<?php

namespace App\Domain\Tenancy\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * Une expression SQL écrite dans le code autour d'une colonne : à la place
 * de « {colonne} », la grammaire de la connexion écrit le nom de la
 * colonne protégé (wrap() : entre guillemets doubles, chacun doublé, la
 * table qualifiée par son préfixe). Elle se passe partout où le
 * constructeur de requêtes accepte une expression (where(), whereRaw(),
 * orderBy(), select()), au lieu d'un SQL brut assemblé avec le nom de la
 * colonne : whereRaw(), orderByRaw() et selectRaw() n'acceptent qu'un
 * texte du code (literal-string), ce que vérifie l'analyse statique. Seul
 * le nom de la colonne vient ainsi d'ailleurs que du code, et toujours
 * protégé par la grammaire.
 */
final readonly class ExpressionDUneColonne implements Expression
{
    public const COLONNE = '{colonne}';

    /**
     * @param  literal-string  $expression  le SQL, avec « {colonne} » à la place de la colonne
     * @param  string  $colonne  la colonne, qualifiée par sa table ou non (« clients.nom »)
     */
    public function __construct(private string $expression, private string $colonne) {}

    public function getValue(Grammar $grammar): string
    {
        return str_replace(self::COLONNE, $grammar->wrap($this->colonne), $this->expression);
    }
}
