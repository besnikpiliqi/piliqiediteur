<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Facturation\Casts\QuantiteArticles;
use Illuminate\Database\Eloquent\Model;
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

    /**
     * Un document qui devient légal (facture émise, facture d'achat
     * validée, devis converti en facture) ne porte que des quantités
     * entières : un ancien brouillon à virgule (enregistré avant le passage
     * aux entiers, voir QuantiteArticles) doit d'abord être corrigé.
     *
     * @param  iterable<Model>  $lignes
     *
     * @throws InvalidArgumentException
     */
    public static function verifierLignes(iterable $lignes): void
    {
        foreach ($lignes as $ligne) {
            if (! is_int($ligne->quantite)) {
                throw new InvalidArgumentException("La ligne « {$ligne->description} » a une quantité à virgule ({$ligne->quantite}) : une quantité doit être un nombre entier d'articles, corrigez-la avant de continuer.");
            }
        }
    }

    /**
     * Pour un retour (avoir client ou fournisseur) : un nombre entier
     * d'articles -- ou bien le reliquat exact d'une ancienne ligne à
     * virgule (1,5 vendu avant le passage aux entiers, 0,5 restant après le
     * retour d'un article), qui solde alors la ligne. Sans cette exception,
     * le dernier demi-article ne pourrait jamais être crédité.
     *
     * @throws InvalidArgumentException
     */
    public static function depuisSaisieOuReliquat(mixed $saisie, int|float $restante, string $libelle = 'La quantité'): int|float
    {
        if (is_float($restante) && ! is_bool($saisie) && is_numeric($saisie) && QuantiteArticles::normaliser($saisie) === $restante) {
            return $restante;
        }

        return self::depuisSaisie($saisie, $libelle);
    }
}
