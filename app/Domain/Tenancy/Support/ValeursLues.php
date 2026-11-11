<?php

namespace App\Domain\Tenancy\Support;

use UnexpectedValueException;

/**
 * Des valeurs lues là où rien ne dit leur type à l'analyse statique (une
 * colonne d'une requête, pluck(), un champ d'une colonne JSON que le code a
 * écrite, une liste passée à une macro, une option d'une commande, une
 * variable d'environnement, un montant que le code écrit en texte), lues
 * pour ce que leur source garantit : un texte, un entier, un booléen, un
 * tableau ou un objet d'une classe, ou une liste de textes ou d'entiers,
 * dans leur ordre. Une autre valeur (une colonne nulle, un champ absent,
 * ou d'un autre type que celui que le code attend) est refusée par une
 * exception, au lieu d'être utilisée pour ce qu'elle n'est pas. La clé
 * d'une fiche saisie dans un écran se lit par cle(), et se cherche parmi
 * des fiches rangées sous leur clé par cleDeTableau().
 */
final class ValeursLues
{
    public const REFUS_TEXTE = 'Une valeur lue n\'est pas un texte : %s.';

    public const REFUS_ENTIER = 'Une valeur lue n\'est pas un entier : %s.';

    public const REFUS_BOOLEEN = 'Une valeur lue n\'est ni vraie ni fausse : %s.';

    public const REFUS_TABLEAU = 'Une valeur lue n\'est pas un tableau : %s.';

    public const REFUS_CHAMP = 'Un tableau lu n\'a pas son champ « %s ».';

    public const REFUS_CLE = 'Une clé saisie n\'est pas une seule clé : %s.';

    public const REFUS_INSTANCE = 'Une valeur lue n\'est pas un objet %s : %s.';

    public const REFUS_CHAMPS = 'Un tableau lu a une clé qui n\'est pas un texte : %d.';

    public const REFUS_ENTIER_OU_TEXTE = 'Une valeur lue n\'est ni un entier ni un texte : %s.';

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
     * L'entier de ce champ d'un tableau lu (une colonne JSON que le code a
     * écrite).
     *
     * @param  array<array-key, mixed>  $tableau
     *
     * @throws UnexpectedValueException un champ absent, ou qui n'est pas un entier
     */
    public static function entierDe(array $tableau, string $champ): int
    {
        return self::entier(self::champ($tableau, $champ));
    }

    /**
     * L'entier de ce champ, ou null quand il est nul.
     *
     * @param  array<array-key, mixed>  $tableau
     *
     * @throws UnexpectedValueException un champ absent, ou ni entier ni nul
     */
    public static function entierOuNulDe(array $tableau, string $champ): ?int
    {
        $valeur = self::champ($tableau, $champ);

        return $valeur === null ? null : self::entier($valeur);
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
        return self::booleen(self::champ($tableau, $champ));
    }

    /**
     * Vrai ou faux, tel quel : ce que rend une méthode qui ne déclare pas
     * son type (le droit qu'une ressource de Filament donne sur une fiche).
     *
     * @throws UnexpectedValueException une valeur ni vraie ni fausse
     */
    public static function booleen(mixed $valeur): bool
    {
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
     * Un texte, ou null quand la valeur est nulle.
     *
     * @throws UnexpectedValueException une valeur ni texte ni nulle
     */
    public static function texteOuNul(mixed $valeur): ?string
    {
        return $valeur === null ? null : self::texte($valeur);
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws UnexpectedValueException une valeur qui n'est pas un tableau
     */
    public static function tableau(mixed $valeur): array
    {
        return is_array($valeur) ? $valeur : throw new UnexpectedValueException(sprintf(self::REFUS_TABLEAU, get_debug_type($valeur)));
    }

    /**
     * Un tableau dont chaque clé est un texte (les champs d'un formulaire,
     * les données d'une validation, une colonne JSON que le code a
     * écrite), rendu tel quel, dans son ordre. Une valeur qui n'est pas un
     * tableau, ou un tableau qui a une clé entière, est refusé.
     *
     * @return array<string, mixed>
     *
     * @throws UnexpectedValueException une valeur qui n'est pas un tableau, ou une clé entière
     */
    public static function champs(mixed $valeur): array
    {
        $champs = [];

        foreach (self::tableau($valeur) as $cle => $champ) {
            $champs[is_string($cle) ? $cle : throw new UnexpectedValueException(sprintf(self::REFUS_CHAMPS, $cle))] = $champ;
        }

        return $champs;
    }

    /**
     * Les lignes d'un répéteur d'un formulaire, ou d'une liste de lignes
     * lue, sous leurs clés et dans leur ordre : chacune un tableau de
     * champs (voir champs()).
     *
     * @return array<array-key, array<string, mixed>>
     *
     * @throws UnexpectedValueException une valeur qui n'est pas un tableau, ou une ligne qui n'est pas un tableau de champs
     */
    public static function lignes(mixed $valeur): array
    {
        return array_map(self::champs(...), self::tableau($valeur));
    }

    /**
     * La valeur au bout de ce chemin de noms dans une valeur lue (une
     * réponse d'un registre, une colonne JSON), ou null quand un maillon
     * manque ou n'est pas un tableau : la lecture $valeur['a']['b'] ?? null,
     * sans rien supposer des maillons.
     */
    public static function valeurAu(mixed $valeur, string ...$noms): mixed
    {
        foreach ($noms as $nom) {
            if (! is_array($valeur) || ! array_key_exists($nom, $valeur)) {
                return null;
            }

            $valeur = $valeur[$nom];
        }

        return $valeur;
    }

    /**
     * Un objet de cette classe (ou d'une classe qui en hérite), là où le
     * code n'a de lui qu'une valeur sans type : ce que rend une méthode de
     * Laravel, de Filament ou de Livewire qui ne déclare pas son type.
     *
     * @template TObjet of object
     *
     * @param  class-string<TObjet>  $classe
     * @return TObjet
     *
     * @throws UnexpectedValueException une valeur d'une autre classe, ou qui n'est pas un objet
     */
    public static function instance(mixed $valeur, string $classe): object
    {
        return $valeur instanceof $classe ? $valeur : throw new UnexpectedValueException(sprintf(self::REFUS_INSTANCE, $classe, get_debug_type($valeur)));
    }

    /**
     * @throws UnexpectedValueException une valeur qui n'est pas un entier
     */
    public static function entier(mixed $valeur): int
    {
        return is_int($valeur) ? $valeur : throw new UnexpectedValueException(sprintf(self::REFUS_ENTIER, get_debug_type($valeur)));
    }

    /**
     * Un entier ou un texte, tel quel : un champ d'un formulaire validé,
     * tel que le navigateur l'envoie (un texte) ou tel que sa valeur par
     * défaut l'a rempli (un entier), que le code passe à ce qui le lit sans
     * le convertir (une quantité saisie, la clé d'une ligne cachée).
     *
     * @throws UnexpectedValueException une valeur ni entière ni texte
     */
    public static function entierOuTexte(mixed $valeur): int|string
    {
        return is_int($valeur) || is_string($valeur) ? $valeur : throw new UnexpectedValueException(sprintf(self::REFUS_ENTIER_OU_TEXTE, get_debug_type($valeur)));
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
     * La clé de tableau que PHP fait d'une clé choisie dans un écran (voir
     * cle()), pour la chercher parmi des fiches rangées sous leur clé comme
     * la cherche Collection::get() : un entier ou un texte tel quel (PHP
     * lit lui-même « 12 » comme la clé 12, mais pas « +12 », « 012 » ni
     * « 12 » entouré d'espaces), un décimal privé de ses décimales (12,5
     * se lit 12), un booléen à 1 ou 0, et rien au texte vide.
     *
     * @throws UnexpectedValueException un tableau ou un objet
     */
    public static function cleDeTableau(mixed $valeur): int|string
    {
        $cle = self::cle($valeur) ?? '';

        return is_int($cle) || is_string($cle) ? $cle : (int) $cle;
    }

    /**
     * La valeur de ce champ d'un tableau lu, telle quelle, même nulle : un
     * champ que le tableau a toujours (une colonne JSON que le code a
     * écrite, un champ requis d'un formulaire validé).
     *
     * @param  array<array-key, mixed>  $tableau
     *
     * @throws UnexpectedValueException un champ absent
     */
    public static function champ(array $tableau, string $champ): mixed
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

    /**
     * Les textes d'une liste lue, chacun sous sa clé, un entier, dans
     * l'ordre de sa source : ce que rend pluck('nom', 'id'), qui lit ces
     * deux colonnes sans faire de modèles, ni donc charger les relations
     * que la requête charge d'avance.
     *
     * @param  iterable<mixed, mixed>  $valeurs
     * @return array<int, string>
     *
     * @throws UnexpectedValueException une clé qui n'est pas un entier, ou une valeur qui n'est pas un texte
     */
    public static function textesParCle(iterable $valeurs): array
    {
        $textes = [];

        foreach ($valeurs as $cle => $valeur) {
            $textes[self::entier($cle)] = self::texte($valeur);
        }

        return $textes;
    }
}
