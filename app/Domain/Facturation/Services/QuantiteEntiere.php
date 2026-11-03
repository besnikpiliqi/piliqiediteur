<?php

namespace App\Domain\Facturation\Services;

use InvalidArgumentException;

/**
 * Une quantité est toujours un nombre entier d'articles (1, 2, 3...),
 * jamais 1,5 : sur une ligne de vente, d'achat, d'avoir comme en stock.
 *
 * Lit la saisie sans jamais la tronquer : en PHP, un (int) ou un
 * paramètre typé int transforme silencieusement 1,5 en 1 -- la facture
 * serait alors fausse sans que personne ne le voie. Une saisie qui n'est
 * pas un entier est donc refusée, jamais arrondie. Accepte en revanche
 * les écritures équivalentes d'un entier ("2", 2.0) qu'envoient les
 * formulaires.
 */
class QuantiteEntiere
{
    /**
     * @throws InvalidArgumentException
     */
    public static function depuisSaisie(mixed $saisie, string $libelle = 'La quantité'): int
    {
        $quantite = is_bool($saisie)
            ? false
            : filter_var($saisie, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($quantite === false) {
            throw new InvalidArgumentException("{$libelle} doit être un nombre entier d'articles, au moins 1.");
        }

        return $quantite;
    }
}
