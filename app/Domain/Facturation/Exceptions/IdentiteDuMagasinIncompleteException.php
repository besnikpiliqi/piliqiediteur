<?php

namespace App\Domain\Facturation\Exceptions;

use LogicException;

/**
 * Une facture à un professionnel, ou son avoir, porte l'identité légale
 * complète du magasin (B11, art. 242 nonies A de l'annexe II au CGI et
 * art. R123-237 du Code de commerce) : tant qu'il en manque une mention,
 * elle ne s'émet pas -- ni numéro consommé, ni signature au registre
 * fiscal. Les tickets de caisse restent possibles (voir
 * Magasin::mentionsLegalesManquantes() et EmettreFactureAction).
 */
class IdentiteDuMagasinIncompleteException extends LogicException
{
    /**
     * @param  list<string>  $mentionsManquantes  dans l'ordre de l'identité du magasin
     */
    public function __construct(public readonly array $mentionsManquantes)
    {
        parent::__construct('Pour émettre une facture à un professionnel, complétez d\'abord l\'identité du magasin (Paramètres > Identité du magasin) : '.implode(', ', $mentionsManquantes).'.');
    }
}
