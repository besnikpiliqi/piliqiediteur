<?php

namespace App\Domain\Stock\Exceptions;

use App\Domain\Stock\Services\ManqueDeStock;
use LogicException;

/**
 * Une vente au-delà du stock disponible, par une personne qui n'est pas
 * autorisée à vendre sans stock dans ce magasin (R1, voir
 * VerificateurDeStock) : elle est refusée, sans numéro consommé ni
 * signature.
 */
final class StockInsuffisantException extends LogicException
{
    /**
     * @param  list<ManqueDeStock>  $manques
     */
    public function __construct(public readonly array $manques)
    {
        parent::__construct('Stock insuffisant : '.implode(' ; ', array_map(fn (ManqueDeStock $manque): string => $manque->refus(), $manques)).'.');
    }
}
