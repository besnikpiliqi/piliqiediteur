<?php

namespace App\Domain\Stock\Exceptions;

use App\Domain\Stock\Services\ManqueDeStock;
use LogicException;

/**
 * Une vente au-delà du stock disponible, par une personne autorisée à
 * vendre sans stock, qui n'a pas encore confirmé l'avertissement, ou dont
 * la confirmation ne couvre plus le stock réel, qui a encore baissé (R1,
 * voir VerificateurDeStock) : rien n'est enregistré, et l'écran montre de
 * nouveau l'avertissement, avec ces manques.
 */
final class ConfirmationVenteSansStockRequiseException extends LogicException
{
    /**
     * @param  list<ManqueDeStock>  $manques
     */
    public function __construct(public readonly array $manques)
    {
        parent::__construct(implode(' ; ', array_map(fn (ManqueDeStock $manque): string => $manque->avertissement(), $manques)).' : confirmer la vente ?');
    }
}
