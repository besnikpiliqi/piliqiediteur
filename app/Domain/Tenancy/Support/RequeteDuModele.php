<?php

namespace App\Domain\Tenancy\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnexpectedValueException;

/**
 * La requête qu'un écran de Filament passe au code (celle d'une
 * ressource, celle qu'un filtre restreint, celle de l'agrégat d'une
 * relation), que Filament ne type que sur Model, lue pour ce qu'elle est :
 * une requête d'un modèle de cette classe, sur la table de ce modèle, dont
 * le code appelle les portées. La requête rendue est la même, inchangée :
 * le filtre ou l'agrégat restreint bien celle que Filament lui a passée.
 * Une autre requête (d'un autre modèle, ou qui lit sa table sous un autre
 * nom, comme celle d'une relation d'un modèle vers lui-même) est une
 * erreur du code, refusée par une exception, au lieu d'être utilisée pour
 * ce qu'elle n'est pas.
 */
final class RequeteDuModele
{
    public const REFUS = 'Une requête lue n\'est pas une requête de %s sur sa table : requête de %s sur %s.';

    /**
     * @template TModele of Model
     *
     * @param  Builder<Model>  $requete
     * @param  class-string<TModele>  $classe
     * @return Builder<TModele>
     *
     * @throws UnexpectedValueException une requête d'un autre modèle, ou sur une autre table
     */
    public static function de(Builder $requete, string $classe): Builder
    {
        $modele = $requete->getModel();
        $table = $requete->getQuery()->from;

        if (! $modele instanceof $classe || $table !== $modele->getTable()) {
            throw new UnexpectedValueException(sprintf(self::REFUS, $classe, $modele::class, is_string($table) ? $table : get_debug_type($table)));
        }

        return $requete->setModel($modele);
    }
}
