<?php

namespace App\Domain\Tenancy\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;

/**
 * La première des valeurs renseignées de ces colonnes (coalesce), pour un
 * tri ou une comparaison : la grammaire de la connexion protège chaque
 * nom de colonne (wrap()), comme le fait ExpressionDUneColonne pour une
 * seule colonne, au lieu d'un SQL brut assemblé avec des noms de colonnes,
 * que orderByRaw() refuse à l'analyse statique (literal-string).
 */
final readonly class PremiereValeurRenseignee implements Expression
{
    /**
     * @param  list<string>  $colonnes  les colonnes, qualifiées par leur table ou non, de la plus prioritaire à la dernière
     */
    public function __construct(private array $colonnes) {}

    public function getValue(Grammar $grammar): string
    {
        return 'coalesce('.implode(', ', array_map(static fn (string $colonne): string => $grammar->wrap($colonne), $this->colonnes)).')';
    }
}
