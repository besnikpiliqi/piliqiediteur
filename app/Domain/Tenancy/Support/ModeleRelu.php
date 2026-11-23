<?php

namespace App\Domain\Tenancy\Support;

use Illuminate\Database\Eloquent\Model;
use UnexpectedValueException;

/**
 * Un modèle relu en base (fresh()), avec les relations demandées, là où il
 * existe forcément : l'action qui le relit vient de l'enregistrer, ou le
 * tient sous son verrou. Un modèle disparu entre-temps est une erreur du
 * code, refusée par une exception, au lieu d'un modèle nul ; jamais le
 * refus d'un geste (une LogicException, que les écrans montrent à la
 * personne, voir GereLesExceptionsMetier) : comme l'erreur de PHP qu'elle
 * remplace, elle remonte jusqu'au journal de l'application.
 */
final class ModeleRelu
{
    public const REFUS = 'Un enregistrement %s n\'existe plus en base : il ne se relit pas.';

    /**
     * @template TModele of Model
     *
     * @param  TModele  $modele
     * @return TModele
     *
     * @throws UnexpectedValueException le modèle n'existe plus
     */
    public static function de(Model $modele, string ...$relations): Model
    {
        return $modele->fresh($relations) ?? throw new UnexpectedValueException(sprintf(self::REFUS, $modele::class));
    }
}
