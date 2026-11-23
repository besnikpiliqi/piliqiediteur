<?php

namespace App\Domain\Demonstration\Bases;

use InvalidArgumentException;

/**
 * Le nom d'une base ou d'un rôle du site de démonstration écrit en
 * identifiant SQL entre guillemets, dans les instructions que PostgreSQL
 * ne prépare pas (CREATE DATABASE, ALTER DATABASE, DROP DATABASE, GRANT,
 * REVOKE, voir BasesDeLaDemonstration et RolesDeLaDemonstration) : chaque
 * caractère, une minuscule, un chiffre ou un tiret bas, est repris de
 * CARACTERES, écrits dans le code, pour que l'instruction reste faite de
 * textes du code (literal-string), ce que vérifie l'analyse statique
 * (DB::unprepared() n'accepte rien d'autre). Tout autre caractère est une
 * erreur du code, refusée par une exception, jamais échappé : les noms
 * des bases (demo_…) et des rôles (demo_admin, demo_app) n'en ont pas.
 */
final class IdentifiantSql
{
    public const REFUS = 'Le nom « %s » n\'est pas un identifiant SQL permis : seulement des minuscules, des chiffres et des tirets bas.';

    /**
     * Les caractères permis, chacun écrit dans le code (les chiffres sous
     * une clé entière, comme PHP range une clé « 0 » à « 9 »).
     *
     * @var array<array-key, literal-string>
     */
    private const CARACTERES = [
        'a' => 'a',
        'b' => 'b',
        'c' => 'c',
        'd' => 'd',
        'e' => 'e',
        'f' => 'f',
        'g' => 'g',
        'h' => 'h',
        'i' => 'i',
        'j' => 'j',
        'k' => 'k',
        'l' => 'l',
        'm' => 'm',
        'n' => 'n',
        'o' => 'o',
        'p' => 'p',
        'q' => 'q',
        'r' => 'r',
        's' => 's',
        't' => 't',
        'u' => 'u',
        'v' => 'v',
        'w' => 'w',
        'x' => 'x',
        'y' => 'y',
        'z' => 'z',
        '0' => '0',
        '1' => '1',
        '2' => '2',
        '3' => '3',
        '4' => '4',
        '5' => '5',
        '6' => '6',
        '7' => '7',
        '8' => '8',
        '9' => '9',
        '_' => '_',
    ];

    /**
     * @return literal-string
     *
     * @throws InvalidArgumentException un nom vide, ou qui a un autre caractère
     */
    public static function de(string $nom): string
    {
        $identifiant = '';

        foreach (str_split($nom) as $caractere) {
            $identifiant .= self::CARACTERES[$caractere] ?? throw new InvalidArgumentException(sprintf(self::REFUS, $nom));
        }

        if ($identifiant === '') {
            throw new InvalidArgumentException(sprintf(self::REFUS, $nom));
        }

        return '"'.$identifiant.'"';
    }
}
