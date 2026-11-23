<?php

namespace Tests\Fixtures\EcartDesPrixDAchat;

use App\Domain\TableauDeBord\Support\Mois;
use JsonException;
use RuntimeException;

/**
 * Les deux fixtures figées de la carte « Écart des prix d'achat » du tableau
 * de bord, que lisent tous les tests du calcul pur :
 *
 *  - verite.json : le relevé de la maquette validée par l'éditeur le
 *    21/11/2026 (le jeu d'achats, les textes, 2 affichages × 12 mois, le
 *    dessin), copié tel quel : la preuve écrite de la maquette ;
 *  - jeux-au-hasard.json : 40 jeux d'achats tirés une fois pour toutes
 *    (graine 36, dont les douze cas forcés), chacun avec le SHA-256 de ses
 *    13 lectures de référence, produites par la référence de calcul de la
 *    maquette (JavaScript, gardée hors du dépôt : aucun outil Node hors
 *    Playwright). Aucun test ne dépend du hasard ni de la date.
 *
 * L'empreinte d'une lecture est celle de son JSON canonique : clés triées à
 * chaque niveau (les listes gardent leur ordre), sans espace, caractères non
 * échappés.
 */
final class LectureDesFixtures
{
    /** @var array<string, mixed>|null */
    private static ?array $verite = null;

    /** @var array<string, mixed>|null */
    private static ?array $jeuxAuHasard = null;

    /**
     * Le relevé de la maquette.
     *
     * @return array<string, mixed>
     */
    public static function verite(): array
    {
        return self::$verite ??= self::lire('verite.json');
    }

    /**
     * Les jeux tirés au hasard et leurs empreintes.
     *
     * @return array<string, mixed>
     */
    public static function jeuxAuHasard(): array
    {
        return self::$jeuxAuHasard ??= self::lire('jeux-au-hasard.json');
    }

    /**
     * Les douze mois de la maquette, « 2025-12 » à « 2026-11 ».
     *
     * @return list<string>
     */
    public static function moisDeLaMaquette(): array
    {
        return Mois::du('2025-12', '2026-11');
    }

    public static function chemin(string $fichier): string
    {
        return __DIR__.'/'.$fichier;
    }

    /**
     * Une lecture de la carte avec les boutons de l'en-tête de la maquette
     * (« Barres ▾ », « Monter », « Descendre »), que la lecture ne porte pas :
     * ils sont ceux de l'en-tête commun de la grille. Elle se compare ainsi au
     * relevé de la maquette et à ses empreintes, clés dans le même ordre.
     *
     * @param  array<string, mixed>  $lecture
     * @return array<string, mixed>
     */
    public static function avecLesBoutonsDeLaMaquette(array $lecture): array
    {
        $complete = [];

        foreach ($lecture as $cle => $valeur) {
            $complete[$cle] = $valeur;

            if ($cle === 'titreCarte') {
                $complete['boutons'] = ['Barres ▾', 'Monter', 'Descendre'];
            }
        }

        return $complete;
    }

    /**
     * Le JSON canonique d'une valeur.
     */
    public static function canonique(mixed $valeur): string
    {
        if (! is_array($valeur)) {
            return json_encode($valeur, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        if (array_is_list($valeur)) {
            return '['.implode(',', array_map(self::canonique(...), $valeur)).']';
        }

        ksort($valeur, SORT_STRING);
        $champs = [];

        foreach ($valeur as $cle => $contenu) {
            $champs[] = json_encode((string) $cle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).':'.self::canonique($contenu);
        }

        return '{'.implode(',', $champs).'}';
    }

    /**
     * Le SHA-256 du JSON canonique d'une lecture.
     */
    public static function empreinte(mixed $valeur): string
    {
        return hash('sha256', self::canonique($valeur));
    }

    /**
     * Le chemin du premier écart entre deux valeurs décodées, dans l'ordre
     * des clés, avec leur type (null quand elles sont identiques).
     */
    public static function premierEcart(mixed $obtenu, mixed $attendu, string $chemin = ''): ?string
    {
        if ($obtenu === $attendu) {
            return null;
        }

        if (! is_array($obtenu) || ! is_array($attendu)) {
            return $chemin.' : obtenu '.var_export($obtenu, true).', attendu '.var_export($attendu, true);
        }

        if (array_keys($obtenu) !== array_keys($attendu)) {
            return $chemin.' : clés obtenues '.json_encode(array_keys($obtenu)).', attendues '.json_encode(array_keys($attendu));
        }

        foreach ($obtenu as $cle => $valeur) {
            $ecart = self::premierEcart($valeur, $attendu[$cle], $chemin.'/'.$cle);

            if ($ecart !== null) {
                return $ecart;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function lire(string $fichier): array
    {
        $contenu = file_get_contents(self::chemin($fichier));

        if ($contenu === false) {
            throw new RuntimeException('Fixture illisible : '.$fichier);
        }

        try {
            $decode = json_decode($contenu, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Fixture illisible : '.$fichier, 0, $exception);
        }

        if (! is_array($decode)) {
            throw new RuntimeException('Fixture illisible : '.$fichier);
        }

        return $decode;
    }
}
