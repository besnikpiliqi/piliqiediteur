<?php

namespace App\Domain\CaisseSecurisee\Exceptions;

use LogicException;

/**
 * Une donnée fiscale validée ne se modifie ni ne se supprime (art. 286
 * I-3° bis du CGI) : une correction s'inscrit par une nouvelle écriture,
 * jamais en réécrivant l'ancienne. Levée par le modèle avant toute
 * requête ; les déclencheurs SQL de la table (voir DeclencheursAjoutSeul)
 * refusent en plus ce qui ne passe pas par le modèle.
 */
class DonneeFiscaleInalterableException extends LogicException
{
    public static function enregistrementFiscal(): self
    {
        return new self('Un enregistrement du registre fiscal ne se modifie ni ne se supprime : une correction s\'inscrit par un nouvel enregistrement.');
    }
}
