<?php

namespace App\Domain\Tenancy\Support;

use UnexpectedValueException;

/**
 * Des valeurs lues là où rien ne dit leur type à l'analyse statique (une
 * colonne d'une requête, pluck(), un champ d'une colonne JSON que le code a
 * écrite, une liste passée à une macro, une option d'une commande, une
 * variable d'environnement, un montant que le code écrit en texte), lues
 * pour ce que leur source garantit : un texte, un entier, un booléen, un
 * tableau ou un nombre écrit en texte, ou une liste de textes ou
 * d'entiers, dans leur ordre. Une autre valeur (une colonne nulle, un champ
 * absent, ou d'un autre type que celui que le code attend) est refusée par
 * une exception, au lieu d'être utilisée pour ce qu'elle n'est pas. La clé
 * d'une fiche saisie dans un écran se lit par cle().
 */
final class ValeursLues
{
    public const REFUS_TEXTE = 'Une valeur lue n\'est pas un texte : %s.';

    public const REFUS_ENTIER = 'Une valeur lue n\'est pas un entier : %s.';

    public const REFUS_NOMBRE = 'Un texte lu n\'est pas un nombre : « %s ».';

    public const REFUS_BOOLEEN = 'Une valeur lue n\'est ni vraie ni fausse : %s.';

    public const REFUS_TABLEAU = 'Une valeur lue n\'est pas un tableau : %s.';

    public const REFUS_CHAMP = 'Un tableau lu n\'a pas son champ « %s ».';

    public const REFUS_CLE = 'Une clé saisie n\'est pas une seule clé : %s.';

    /**
     * Le texte de ce champ d'un tableau lu (une colonne JSON que le code a
     * écrite).
     *
     * @param  array<array-key, mixed>  $tableau
     *
     * @throws UnexpectedValueException un champ absent, ou qui n'est pas un texte
     */
    public static function texteDe(array $tableau, string $champ): string
    {
        return self::texte(self::champ($tableau, $champ));
    }

    /**
     * Le texte de ce champ, ou null quand il est nul.
     *
     * @param  array<array-key, mixed>  $tableau
     *
     * @throws UnexpectedValueException un champ absent, ou ni texte ni nul
     */
    public static function texteOuNulDe(array $tableau, string $champ): ?string
    {
        $valeur = self::champ($tableau, $champ);

        return $valeur === null ? null : self::texte($valeur);
    }

    /**
     * @param  array<array-key, mixed>  $tableau
     *
     * @throws UnexpectedValueException un champ absent, ou ni vrai ni faux
     */
    public static function booleenDe(array $tableau, string $champ): bool
    {
        $valeur = self::champ($tableau, $champ);

        return is_bool($valeur) ? $valeur : throw new UnexpectedValueException(sprintf(self::REFUS_BOOLEEN, get_debug_type($valeur)));
    }

    /**
     * @param  array<array-key, mixed>  $tableau
     * @return array<array-key, mixed>
     *
     * @throws UnexpectedValueException un champ absent, ou qui n'est pas un tableau
     */
    public static function tableauDe(array $tableau, string $champ): array
    {
        $valeur = self::champ($tableau, $champ);

        return is_array($valeur) ? $valeur : throw new UnexpectedValueException(sprintf(self::REFUS_TABLEAU, get_debug_type($valeur)));
    }

    /**
     * @throws UnexpectedValueException une valeur qui n'est pas un texte
     */
    public static function texte(mixed $valeur): string
    {
        return is_string($valeur) ? $valeur : throw new UnexpectedValueException(sprintf(self::REFUS_TEXTE, get_debug_type($valeur)));
    }

    /**
     * @throws UnexpectedValueException une valeur qui n'est pas un entier
     */
    public static function entier(mixed $valeur): int
    {
        return is_int($valeur) ? $valeur : throw new UnexpectedValueException(sprintf(self::REFUS_ENTIER, get_debug_type($valeur)));
    }

    /**
     * La clé d'une fiche choisie dans un écran (un Select, une ligne d'un
     * répéteur), telle que le navigateur l'envoie : un texte, un nombre, un
     * booléen ou rien, que whereKey() lit comme find() lisait une seule
     * clé. Un tableau, que find() lisait comme plusieurs clés (une
     * collection, sur laquelle l'écran s'arrêtait par une erreur) et que
     * seule une requête forgée envoie à la place d'une clé, est refusé.
     *
     * @throws UnexpectedValueException un tableau ou un objet
     */
    public static function cle(mixed $valeur): int|string|float|bool|null
    {
        return $valeur === null || is_scalar($valeur) ? $valeur : throw new UnexpectedValueException(sprintf(self::REFUS_CLE, get_debug_type($valeur)));
    }

    /**
     * Un nombre écrit en texte (« 120.00 »), tel que PHP le reconnaît
     * (is_numeric()), sans le convertir.
     *
     * @return numeric-string
     *
     * @throws UnexpectedValueException un texte qui n'est pas un nombre
     */
    public static function nombre(string $valeur): string
    {
        return is_numeric($valeur) ? $valeur : throw new UnexpectedValueException(sprintf(self::REFUS_NOMBRE, $valeur));
    }

    /**
     * @param  array<array-key, mixed>  $tableau
     *
     * @throws UnexpectedValueException un champ absent
     */
    private static function champ(array $tableau, string $champ): mixed
    {
        return array_key_exists($champ, $tableau) ? $tableau[$champ] : throw new UnexpectedValueException(sprintf(self::REFUS_CHAMP, $champ));
    }

    /**
     * @param  iterable<mixed>  $valeurs
     * @return list<string>
     *
     * @throws UnexpectedValueException une valeur qui n'est pas un texte
     */
    public static function textes(iterable $valeurs): array
    {
        $textes = [];

        foreach ($valeurs as $valeur) {
            $textes[] = self::texte($valeur);
        }

        return $textes;
    }

    /**
     * @param  iterable<mixed>  $valeurs
     * @return list<int>
     *
     * @throws UnexpectedValueException une valeur qui n'est pas un entier
     */
    public static function entiers(iterable $valeurs): array
    {
        $entiers = [];

        foreach ($valeurs as $valeur) {
            $entiers[] = self::entier($valeur);
        }

        return $entiers;
    }
}
