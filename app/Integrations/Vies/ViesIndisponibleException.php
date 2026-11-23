<?php

namespace App\Integrations\Vies;

use LogicException;

/**
 * VIES n'a pas pu dire si le n° de TVA est valide : le service de la
 * Commission ou celui de l'État membre ne répond pas, est saturé, ou
 * répond ce que le logiciel ne sait pas lire (voir ClientVies). Aucune
 * vérification n'est enregistrée, et le message, rédigé pour le magasin,
 * rappelle la conséquence : sans n° vérifié, pas d'exonération.
 */
class ViesIndisponibleException extends LogicException
{
    public function __construct()
    {
        parent::__construct('Le service européen VIES ne répond pas pour ce pays : réessayez dans quelques minutes. Sans n° de TVA vérifié, la vente reste à la TVA française.');
    }
}
