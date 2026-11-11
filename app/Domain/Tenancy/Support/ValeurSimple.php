<?php

namespace App\Domain\Tenancy\Support;

use UnexpectedValueException;

/**
 * Une valeur simple (un texte, un nombre, un booléen ou rien), lue en
 * texte ou en entier comme le code la lisait par une conversion : une
 * valeur saisie dans un écran (un champ d'un formulaire de Filament, lu
 * par $get ou dans $data, une propriété d'un composant Livewire, un champ
 * d'une requête) ou lue sans type (l'état d'origine d'une fiche, une
 * variable d'environnement). Sous chaque forme que le navigateur, Livewire
 * et la base rendent vraiment, elle donne le même résultat que (string)
 * ou (int) : « 12 » et 12 rendent 12, un champ vide ou rien rend « » ou
 * 0. Un tableau ou un objet, que seule une requête forgée envoie à la
 * place d'une valeur simple, est refusé par une exception, au lieu d'être
 * converti (« Array », 1). simple() rend la valeur elle-même, pour la
 * conversion que le code lui applique déjà (un montant saisi lu par
 * (float), une case lue par (bool)) ou pour une fonction qui accepte toute
 * valeur simple.
 */
final class ValeurSimple
{
    public const REFUS = 'Une valeur lue n\'est pas une valeur simple : %s.';

    /**
     * @throws UnexpectedValueException un tableau ou un objet
     */
    public static function enTexte(mixed $valeur): string
    {
        return (string) self::simple($valeur);
    }

    /**
     * Un texte, ou null quand la valeur est nulle : un champ facultatif.
     *
     * @throws UnexpectedValueException un tableau ou un objet
     */
    public static function enTexteOuNul(mixed $valeur): ?string
    {
        return $valeur === null ? null : self::enTexte($valeur);
    }

    /**
     * Un entier, ou null quand la valeur est nulle : un identifiant
     * facultatif.
     *
     * @throws UnexpectedValueException un tableau ou un objet
     */
    public static function enEntierOuNul(mixed $valeur): ?int
    {
        return $valeur === null ? null : self::enEntier($valeur);
    }

    /**
     * @throws UnexpectedValueException un tableau ou un objet
     */
    public static function enEntier(mixed $valeur): int
    {
        return (int) self::simple($valeur);
    }

    /**
     * @throws UnexpectedValueException un tableau ou un objet
     */
    public static function simple(mixed $valeur): int|string|float|bool|null
    {
        return $valeur === null || is_scalar($valeur) ? $valeur : throw new UnexpectedValueException(sprintf(self::REFUS, get_debug_type($valeur)));
    }
}
