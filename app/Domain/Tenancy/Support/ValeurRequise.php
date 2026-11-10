<?php

namespace App\Domain\Tenancy\Support;

use UnexpectedValueException;

/**
 * Une valeur qui peut être nulle en général (une colonne ou une relation
 * facultative, une date posée plus tard), mais pas là où le code la lit :
 * l'état du document ou de la demande la garantit (une facture émise a
 * son échéance, un paiement enregistré sa date). Nulle, elle est refusée
 * par une exception qui la nomme, au lieu d'être utilisée pour ce qu'elle
 * n'est pas : une erreur du code, ou des données altérées en base, que la
 * vérification de l'intégrité signale alors comme un document qui ne se
 * relit plus (voir VerificateurIntegrite). L'exception n'est jamais le
 * refus d'un geste (une LogicException, que les écrans montrent à la
 * personne, voir GereLesExceptionsMetier) : comme l'erreur de PHP qu'elle
 * remplace, elle remonte jusqu'au journal de l'application.
 */
final class ValeurRequise
{
    public const REFUS = 'Une valeur requise manque : %s.';

    /**
     * @template TValeur
     *
     * @param  TValeur|null  $valeur
     * @param  string  $quoi  ce qu'est la valeur (« l'échéance de la facture émise »)
     * @return TValeur
     *
     * @throws UnexpectedValueException une valeur nulle
     */
    public static function de(mixed $valeur, string $quoi): mixed
    {
        return $valeur ?? throw new UnexpectedValueException(sprintf(self::REFUS, $quoi));
    }
}
