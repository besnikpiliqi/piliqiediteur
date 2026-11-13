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

    public const REFUS_SOI_MEME = 'Une requête lue n\'est pas la sous-requête d\'une relation de %s vers lui-même : requête de %s sur %s.';

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

    /**
     * La sous-requête qu'Eloquent écrit pour une relation d'un modèle de
     * cette classe vers lui-même (withExists() sur Facture::elleMeme(),
     * voir FactureResource::getEloquentQuery()) : la table du modèle y est
     * lue sous un autre nom (« factures as laravel_reserved_0 »), que
     * porte le modèle de la requête, si bien que ses portées s'y écrivent
     * telles quelles. La requête rendue est la même, inchangée : setModel(),
     * qui la type, la fait lire sous le seul nom que porte le modèle, et la
     * table qu'elle lisait lui est aussitôt rendue. Toute autre requête
     * (d'un autre modèle, ou sur la table sous son propre nom) est refusée.
     *
     * @template TModele of Model
     *
     * @param  Builder<Model>  $requete
     * @param  class-string<TModele>  $classe
     * @return Builder<TModele>
     *
     * @throws UnexpectedValueException une requête d'un autre modèle, ou qui ne lit pas sa table sous le nom que porte son modèle
     */
    public static function deSoiMeme(Builder $requete, string $classe): Builder
    {
        $modele = $requete->getModel();
        $table = $requete->getQuery()->from;

        if (! $modele instanceof $classe || ! is_string($table) || $table !== (new $classe)->getTable().' as '.$modele->getTable()) {
            throw new UnexpectedValueException(sprintf(self::REFUS_SOI_MEME, $classe, $modele::class, is_string($table) ? $table : get_debug_type($table)));
        }

        $lue = $requete->setModel($modele);
        $lue->getQuery()->from($table);

        return $lue;
    }
}
