<?php

namespace App\Filament;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * L'ordre par défaut d'une liste de documents ou de fiches : le plus récent
 * en premier, les derniers au début (décision de l'éditeur du 17/11/2026),
 * puis l'identifiant décroissant en départage, jamais un tri sur le numéro
 * en texte. La personne le change en cliquant sur le titre d'une colonne
 * triable ; ce tri-ci ne vaut que tant qu'elle n'en a choisi aucun.
 */
final class PlusRecentEnPremier
{
    /**
     * Par la date de la fiche, celle de sa création : un client, un
     * fournisseur, un produit, un compte.
     *
     * @template TModele of Model
     *
     * @param  Builder<TModele>  $requete
     * @return Builder<TModele>
     */
    public static function parCreation(Builder $requete): Builder
    {
        $modele = $requete->getModel();

        return $requete->orderByDesc($modele->qualifyColumn('created_at'))->orderByDesc($modele->getQualifiedKeyName());
    }

    /**
     * Par la date d'un document, celle de son émission, et celle de sa
     * création tant qu'il n'est pas émis (un brouillon, qui n'en a pas
     * encore) : un brouillon créé aujourd'hui se range avec les documents
     * d'aujourd'hui, jamais au bout de la liste.
     *
     * @template TModele of Model
     *
     * @param  Builder<TModele>  $requete
     * @return Builder<TModele>
     */
    public static function parEmission(Builder $requete): Builder
    {
        $modele = $requete->getModel();
        $grammaire = $requete->getQuery()->getGrammar();
        $emission = $grammaire->wrap($modele->qualifyColumn('date_emission'));
        $creation = $grammaire->wrap($modele->qualifyColumn('created_at'));

        return $requete->orderByRaw("coalesce({$emission}, {$creation}) desc")->orderByDesc($modele->getQualifiedKeyName());
    }

    /**
     * Par la date d'un jour d'un document, celle de la réception d'un
     * paiement, puis celle de son enregistrement.
     *
     * @template TModele of Model
     *
     * @param  Builder<TModele>  $requete
     * @return Builder<TModele>
     */
    public static function parJour(Builder $requete, string $colonneDuJour): Builder
    {
        $modele = $requete->getModel();

        return $requete->orderByDesc($modele->qualifyColumn($colonneDuJour))->orderByDesc($modele->qualifyColumn('created_at'))->orderByDesc($modele->getQualifiedKeyName());
    }
}
