<?php

namespace App\Domain\TableauDeBord\Support;

use InvalidArgumentException;

/**
 * Les formats de la carte « Écart des prix d'achat » du tableau de bord,
 * ceux de la maquette validée par l'éditeur (le 21/11/2026), caractère pour
 * caractère : un montant s'écrit « 15 928,61 € » (espace fine insécable
 * U+202F entre les milliers, espace insécable U+00A0 avant « € »), un prix
 * unitaire « 19,54 € » (espace ordinaire avant « € »), un montant signé
 * « +22,80 € » ou « −4,57 € » (U+2212), un pourcentage « +1,8 % » (espace
 * ordinaire avant « % »), une graduation du dessin « +30 € », sans
 * séparateur de milliers ni décimale.
 *
 * Les montants sont des centimes entiers, d'au plus 9 007 199 254 740 991 en
 * valeur absolue (les entiers sûrs de la maquette) : au-delà, une exception
 * est levée, jamais un nombre à virgule silencieux.
 *
 * Le pourcentage est celui de la maquette : le quotient est un nombre à
 * virgule en double précision, calculé dans l'ordre « (écart ÷ base) × 100 »,
 * puis arrondi à une décimale, la moitié vers le haut, à partir de son
 * écriture décimale la plus courte (celle que donne JavaScript, la plus
 * courte qui se relit comme le même nombre) : 9 ÷ 2 000 × 100 s'écrit
 * 0,44999999999999996 et donne « 0,4 % », alors que la fraction exacte,
 * 0,45, donnerait « 0,5 % » (décision de l'éditeur : la maquette fait foi).
 * Le signe est celui de l'écart, pas celui du nombre arrondi (« +0,0 % »).
 * L'arrondi se fait sur les chiffres de cette écriture, par chaîne de
 * caractères, sans réglage `precision` de PHP ni nombre géant.
 *
 * Les nombres à virgule du dessin s'écrivent comme JavaScript les écrit
 * (nombreCommeJavaScript()), pour que le dessin soit celui de la maquette.
 */
final class EcritureDesEcarts
{
    /** Le plus grand entier sûr de la maquette (2^53 − 1). */
    public const ENTIER_SUR_MAXIMAL = 9007199254740991;

    private const MOINS = "\u{2212}";

    private const ESPACE_FINE_INSECABLE = "\u{202F}";

    private const ESPACE_INSECABLE = "\u{00A0}";

    /**
     * Un montant en euros : « 1 234,56 € ».
     */
    public static function euros(int $centimes): string
    {
        self::exigerDesCentimes($centimes);

        return self::ecrireLesCentimes($centimes).self::ESPACE_INSECABLE.'€';
    }

    /**
     * Un prix unitaire : « 4,85 € », l'espace avant « € » étant ordinaire.
     */
    public static function prix(int $centimes): string
    {
        self::exigerDesCentimes($centimes);

        return self::ecrireLesCentimes($centimes).' €';
    }

    /**
     * Un montant signé : « + » s'il est positif, « − » (U+2212) s'il est
     * négatif, rien s'il est nul, puis le montant en valeur absolue.
     */
    public static function signe(int $centimes): string
    {
        self::exigerDesCentimes($centimes);

        $signe = $centimes > 0 ? '+' : ($centimes < 0 ? self::MOINS : '');

        return $signe.self::euros(abs($centimes));
    }

    /**
     * Un nombre entier, avec l'espace fine insécable entre les milliers :
     * « 1 510 ».
     */
    public static function entier(int $nombre): string
    {
        self::exigerDesCentimes($nombre);

        return ($nombre < 0 ? '-' : '').self::grouper((string) abs($nombre));
    }

    /**
     * Le pourcentage d'un écart sur une base, tous deux en centimes : « — »
     * quand la base est nulle, sinon le signe de l'écart, la valeur absolue
     * arrondie à une décimale et « % » (voir la classe).
     */
    public static function pourcentage(int $ecart, int $base): string
    {
        self::exigerDesCentimes($ecart);
        self::exigerDesCentimes($base);

        if ($base === 0) {
            return '—';
        }

        $quotient = abs((float) $ecart / (float) $base * 100);
        $dixiemes = self::dixiemesArrondis($quotient);
        $entier = strlen($dixiemes) > 1 ? substr($dixiemes, 0, -1) : '0';
        $decimale = substr($dixiemes, -1);
        $signe = $ecart > 0 ? '+' : ($ecart < 0 ? self::MOINS : '');

        return $signe.self::grouper($entier).','.$decimale.' %';
    }

    /**
     * Une graduation de l'axe : « +30 € », « −20 € », « 0 € » (aucun
     * séparateur de milliers, aucune décimale).
     */
    public static function graduation(float $valeur): string
    {
        $signe = $valeur > 0 ? '+' : ($valeur < 0 ? self::MOINS : '');

        return $signe.self::nombreCommeJavaScript(abs($valeur)).' €';
    }

    /**
     * Un nombre à virgule écrit comme JavaScript l'écrit (`String(x)`) :
     * l'écriture la plus courte qui se relit comme le même nombre,
     * positionnelle quand 1e-6 ≤ |x| < 1e21 et avec exposant au-delà,
     * « 0 » pour -0 et « NaN » pour NAN.
     */
    public static function nombreCommeJavaScript(float $nombre): string
    {
        if (is_nan($nombre)) {
            return 'NaN';
        }

        if ($nombre === 0.0) {
            return '0';
        }

        if (is_infinite($nombre)) {
            return $nombre > 0 ? 'Infinity' : '-Infinity';
        }

        [$chiffres, $exposant] = self::chiffresLesPlusCourts(abs($nombre));
        $signe = $nombre < 0 ? '-' : '';
        $nombreDeChiffres = strlen($chiffres);
        $position = $exposant + 1;

        if ($nombreDeChiffres <= $position && $position <= 21) {
            return $signe.$chiffres.str_repeat('0', $position - $nombreDeChiffres);
        }

        if ($position > 0 && $position <= 21) {
            return $signe.substr($chiffres, 0, $position).'.'.substr($chiffres, $position);
        }

        if ($position > -6 && $position <= 0) {
            return $signe.'0.'.str_repeat('0', -$position).$chiffres;
        }

        $puissance = $position - 1;
        $exposantEcrit = 'e'.($puissance >= 0 ? '+' : '-').abs($puissance);

        if ($nombreDeChiffres === 1) {
            return $signe.$chiffres.$exposantEcrit;
        }

        return $signe.$chiffres[0].'.'.substr($chiffres, 1).$exposantEcrit;
    }

    private static function exigerDesCentimes(int $nombre): void
    {
        if ($nombre > self::ENTIER_SUR_MAXIMAL || $nombre < -self::ENTIER_SUR_MAXIMAL) {
            throw new InvalidArgumentException('Un montant doit être un nombre entier de centimes d\'au plus 9 007 199 254 740 991 en valeur absolue : '.$nombre);
        }
    }

    /**
     * « 1 234,56 » : les euros groupés par trois et deux chiffres de
     * centimes, le signe « - » ASCII devant un montant négatif.
     */
    private static function ecrireLesCentimes(int $centimes): string
    {
        $absolu = abs($centimes);

        return ($centimes < 0 ? '-' : '').self::grouper((string) intdiv($absolu, 100)).','.str_pad((string) ($absolu % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * Les chiffres d'un entier positif, groupés par trois depuis la droite.
     */
    private static function grouper(string $chiffres): string
    {
        $longueur = strlen($chiffres);
        $sortie = '';

        for ($i = 0; $i < $longueur; $i++) {
            if ($i > 0 && ($longueur - $i) % 3 === 0) {
                $sortie .= self::ESPACE_FINE_INSECABLE;
            }

            $sortie .= $chiffres[$i];
        }

        return $sortie;
    }

    /**
     * Les chiffres significatifs les plus courts d'un nombre positif fini
     * (de 1 à 17) qui se relisent comme le même nombre, et l'exposant du
     * premier : 191.60000000000002 donne « 19160000000000002 » et 2 ; 100
     * donne « 1 » et 2. Ils se trouvent sans réglage `precision` de PHP.
     *
     * @return array{0: string, 1: int}
     */
    private static function chiffresLesPlusCourts(float $nombre): array
    {
        for ($precision = 0; $precision < 16; $precision++) {
            $ecrit = sprintf('%.'.$precision.'e', $nombre);

            if ((float) $ecrit === $nombre) {
                return self::lireLEcritureScientifique($ecrit);
            }
        }

        return self::lireLEcritureScientifique(sprintf('%.16e', $nombre));
    }

    /**
     * « 1.9160000000000002e+2 » donne « 19160000000000002 » et 2, sans les
     * zéros de fin.
     *
     * @return array{0: string, 1: int}
     */
    private static function lireLEcritureScientifique(string $ecrit): array
    {
        if (preg_match('/^(\d)(?:\.(\d+))?e([+-]\d+)$/', $ecrit, $parties) !== 1) {
            throw new InvalidArgumentException('Nombre illisible : '.$ecrit);
        }

        $chiffres = rtrim($parties[1].$parties[2], '0');

        return [$chiffres === '' ? '0' : $chiffres, (int) $parties[3]];
    }

    /**
     * Le quotient (positif ou nul) multiplié par 10 et arrondi à l'entier,
     * la moitié vers le haut, depuis son écriture décimale la plus courte :
     * le nombre de dixièmes, écrit en chiffres. 0,44999999999999996 donne
     * « 4 », 9,95 donne « 100 ».
     */
    private static function dixiemesArrondis(float $quotient): string
    {
        if ($quotient === 0.0) {
            return '0';
        }

        [$chiffres, $exposant] = self::chiffresLesPlusCourts($quotient);
        $nombreDeChiffres = strlen($chiffres);
        $decalage = $exposant + 1 - $nombreDeChiffres + 1;

        if ($decalage >= 0) {
            return $chiffres.str_repeat('0', $decalage);
        }

        $entiers = $nombreDeChiffres + $decalage;

        if ($entiers > 0) {
            $partieEntiere = substr($chiffres, 0, $entiers);
            $suivant = (int) $chiffres[$entiers];
        } elseif ($entiers === 0) {
            $partieEntiere = '0';
            $suivant = (int) $chiffres[0];
        } else {
            return '0';
        }

        if ($suivant >= 5) {
            return self::ajouterUn($partieEntiere);
        }

        return self::sansZerosDeTete($partieEntiere);
    }

    /**
     * Ajoute un à un entier écrit en chiffres, retenue comprise.
     */
    private static function ajouterUn(string $chiffres): string
    {
        $position = strlen($chiffres) - 1;

        while ($position >= 0) {
            if ($chiffres[$position] === '9') {
                $chiffres[$position] = '0';
                $position--;

                continue;
            }

            $chiffres[$position] = (string) ((int) $chiffres[$position] + 1);

            return self::sansZerosDeTete($chiffres);
        }

        return '1'.$chiffres;
    }

    private static function sansZerosDeTete(string $chiffres): string
    {
        $sans = ltrim($chiffres, '0');

        return $sans === '' ? '0' : $sans;
    }
}
