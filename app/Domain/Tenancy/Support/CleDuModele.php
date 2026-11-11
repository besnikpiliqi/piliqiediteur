<?php

namespace App\Domain\Tenancy\Support;

use Illuminate\Database\Eloquent\Model;
use UnexpectedValueException;

/**
 * La clé d'un enregistrement lu ou écrit en base, là où le code s'en sert
 * comme d'un entier (une comparaison stricte, un identifiant inscrit au
 * journal d'audit ou dans un message) : un entier, la clé
 * auto-incrémentée de chaque table du logiciel, qu'Eloquent rend en
 * entier (voir Model::getCasts()). Un modèle sans clé entière (qui n'est
 * pas encore en base) est une erreur du code, refusée par une exception,
 * au lieu d'une clé convertie en 0 ; jamais le refus d'un geste (une
 * LogicException, que les écrans montrent à la personne, voir
 * GereLesExceptionsMetier) : elle remonte jusqu'au journal de
 * l'application.
 */
final class CleDuModele
{
    public const REFUS = 'Un enregistrement %s n\'a pas de clé entière : %s.';

    /**
     * @throws UnexpectedValueException une clé qui n'est pas un entier
     */
    public static function de(Model $modele): int
    {
        $cle = $modele->getKey();

        return is_int($cle) ? $cle : throw new UnexpectedValueException(sprintf(self::REFUS, $modele::class, get_debug_type($cle)));
    }
}
