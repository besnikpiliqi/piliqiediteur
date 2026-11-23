<?php

namespace App\Domain\TableauDeBord\Support;

use App\Domain\TableauDeBord\Enums\NiveauDeLaBarre;
use App\Domain\TableauDeBord\Enums\TypeDeGraphique;
use Closure;

/**
 * Le dessin d'une carte par période (choix de l'éditeur du 16/11/2026, lot
 * GRAPH-AN ; maquette 01-graph-an.html du 22/11/2026 ; décision de Claude du
 * 22/11/2026, D76 : le dessin est un `svg` écrit par le serveur, comme celui
 * de l'écart des prix d'achat, parce qu'un clic sur une barre, Entrée et
 * Espace, une étiquette lue par un lecteur d'écran sur chaque barre et les
 * barres vertes ou rouges de l'écart ne se font pas sur le canevas de
 * Chart.js) : une colonne par barre de la série (voir Barre), de gauche à
 * droite, avec sa grille, ses graduations, ses barres, ses courbes et ses
 * aires, la série de l'an dernier en gris et en pointillés, la tendance en
 * encre, les barres de l'écart en % vertes au-dessus de 0 et rouges en
 * dessous, « – » quand l'année d'avant valait 0.
 *
 * Tout se calcule sur les valeurs de la série, en centimes (ou en centièmes
 * de point pour un écart), jamais sur un montant écrit ; les nombres écrits
 * passent par $euros pour les montants. Chaque colonne porte, en plus de son
 * dessin : son étiquette lue (`aria-label`), son info-bulle (`data-info-bulle`,
 * du JSON que la feuille de script lit avec textContent, jamais du HTML) et,
 * quand la barre se détaille ou ouvre la liste de son jour, les gestes de la
 * grille (`choisirLaBarre`) : le clic, Entrée et Espace. Les gestes sont
 * écrits tels quels, pour que l'inventaire des actions les lise (voir
 * ActionsDeLInterface). Les couleurs sont des variables de la carte
 * (--periode-s1 à --periode-s5, --periode-avant, --periode-encre,
 * --periode-hausse, --periode-baisse, --periode-grille, --periode-axe,
 * --periode-texte, --periode-doux, --periode-carte, --periode-survol), que
 * la feuille des graphiques donne en clair et en sombre.
 *
 * @phpstan-type Trace array{valeurs: list<int|null>, unite: string, forme: 'bar'|'line'|'area', cle: string, couleur: string, pointilles: bool, axe: 'g'|'d', role: string, signe: bool, libelle: string}
 */
final class SvgDesBarresParPeriode
{
    /** La largeur du cadre : la carte se met à l'échelle de sa place. */
    private const LARGEUR = 420.0;

    private const HAUTEUR = 240.0;

    /** La place d'une seconde ligne d'étiquettes (l'année sous le mois). */
    private const SECONDE_LIGNE = 14.0;

    private const HAUT = 12.0;

    private const BAS = 26.0;

    private const NOMBRE_DE_GRADUATIONS = 4;

    /** La largeur d'un caractère de graduation, pour la marge de l'axe. */
    private const CARACTERE = 6.4;

    /** Au-delà, les points d'une courbe ne se marquent plus. */
    private const POINTS_AU_PLUS = 40;

    /** Les couleurs de série de la carte, dans l'ordre. */
    private const SERIES = 5;

    /**
     * Le `svg` en entier, prêt à être écrit dans la page.
     *
     * @param  Closure(int): string  $euros  un montant en centimes, tel que l'écran l'écrit
     * @param  string  $graphique  la valeur du graphique, que chaque colonne passe à son geste
     * @param  bool  $cliquable  une barre se détaille ou ouvre la liste de son jour
     * @param  bool  $cumul  les séries sont des cumuls depuis le début de l'année ou de l'exercice
     */
    public static function ecrire(SerieDuGraphique $serie, TypeDeGraphique $type, string $graphique, string $titre, bool $cliquable, bool $cumul, Closure $euros): string
    {
        $barres = $serie->barres;
        $nombre = count($barres);
        $traces = self::traces($serie, $type, $cumul);
        $etiquettes = self::etiquettes($barres);
        $deuxLignes = array_filter($etiquettes, fn (array $etiquette): bool => $etiquette[1] !== null) !== [];
        $hauteur = self::HAUTEUR + ($deuxLignes ? self::SECONDE_LIGNE : 0.0);
        $bas = self::BAS + ($deuxLignes ? self::SECONDE_LIGNE : 0.0);

        $echelleGauche = self::echelle(self::valeursDe($traces, 'g'));
        $echelleDroite = array_filter($traces, fn (array $trace): bool => $trace['axe'] === 'd') === [] ? null : self::echelle(self::valeursDe($traces, 'd'));
        $uniteGauche = self::uniteDe($traces, 'g');
        $uniteDroite = self::uniteDe($traces, 'd');
        $gauche = max(strlen(self::graduation($echelleGauche['min'], $uniteGauche)), strlen(self::graduation($echelleGauche['max'], $uniteGauche)), 4) * self::CARACTERE + 12;
        $droite = $echelleDroite === null ? 8.0 : max(strlen(self::graduation($echelleDroite['min'], $uniteDroite)), strlen(self::graduation($echelleDroite['max'], $uniteDroite)), 4) * self::CARACTERE + 12;
        $largeurDuTrace = self::LARGEUR - $gauche - $droite;
        $hauteurDuTrace = $hauteur - self::HAUT - $bas;
        $colonne = $largeurDuTrace / max(1, $nombre);

        $y = fn (float $valeur, string $axe = 'g'): float => self::position($valeur, $axe === 'd' && $echelleDroite !== null ? $echelleDroite : $echelleGauche, $hauteurDuTrace);
        $x = fn (int $rang): float => $gauche + $colonne * ($rang + 0.5);

        $enBarres = array_values(array_filter($traces, fn (array $trace): bool => $trace['forme'] === 'bar'));
        $largeurDeBarre = min(40.0, ($colonne * 0.74) / max(1, count($enBarres)));
        $largeurDuGroupe = $largeurDeBarre * max(1, count($enBarres)) + (max(1, count($enBarres)) - 1) * 2;

        $dessin = '';

        for ($graduation = $echelleGauche['min']; $graduation <= $echelleGauche['max'] + $echelleGauche['pas'] / 2; $graduation += $echelleGauche['pas']) {
            $position = self::n($y($graduation));
            $dessin .= '<line x1="'.self::n($gauche).'" x2="'.self::n(self::LARGEUR - $droite).'" y1="'.$position.'" y2="'.$position.'" stroke="'.($graduation === 0.0 ? 'var(--periode-axe)' : 'var(--periode-grille)').'" stroke-width="'.($graduation === 0.0 ? '1.4' : '1').'"/>';
            $dessin .= '<text x="'.self::n($gauche - 6).'" y="'.self::n($y($graduation) + 4).'" text-anchor="end" font-size="11.5" fill="var(--periode-doux)">'.self::echapper(self::graduation($graduation, $uniteGauche)).'</text>';
        }

        if ($echelleDroite !== null) {
            for ($graduation = $echelleDroite['min']; $graduation <= $echelleDroite['max'] + $echelleDroite['pas'] / 2; $graduation += $echelleDroite['pas']) {
                $dessin .= '<text x="'.self::n(self::LARGEUR - $droite + 6).'" y="'.self::n($y($graduation, 'd') + 4).'" font-size="11.5" fill="var(--periode-doux)">'.self::echapper(self::graduation($graduation, $uniteDroite)).'</text>';
            }
        }

        foreach ($barres as $rang => $barre) {
            foreach ($enBarres as $rangDeBarre => $trace) {
                $valeur = $trace['valeurs'][$rang] ?? null;

                if ($valeur === null) {
                    continue;
                }

                $depart = $x($rang) - $largeurDuGroupe / 2 + $rangDeBarre * ($largeurDeBarre + 2);

                if ($valeur === 0) {
                    $dessin .= '<rect class="zero" x="'.self::n($depart).'" y="'.self::n($y(0.0) - 1.5).'" width="'.self::n($largeurDeBarre).'" height="3" rx="1.5" fill="var(--periode-doux)"/>';

                    continue;
                }

                $couleur = $trace['signe'] ? ($valeur >= 0 ? 'var(--periode-hausse)' : 'var(--periode-baisse)') : $trace['couleur'];
                $haut = $y((float) max($valeur, 0));
                $bas0 = $y((float) min($valeur, 0));
                $dessin .= '<rect x="'.self::n($depart).'" y="'.self::n($haut).'" width="'.self::n($largeurDeBarre).'" height="'.self::n(max(2.0, $bas0 - $haut)).'" rx="3" fill="'.$couleur.'" stroke="var(--periode-carte)" stroke-width="1"/>';
            }

            if ($barre->aVenir === false && self::sansEcart($traces, $rang)) {
                $dessin .= '<text class="tiret" x="'.self::n($x($rang)).'" y="'.self::n($y(0.0) - 6).'" text-anchor="middle" font-size="13" fill="var(--periode-doux)">–</text>';
            }
        }

        foreach ($traces as $trace) {
            if ($trace['forme'] === 'bar') {
                continue;
            }

            $chemin = '';
            $ouvert = false;
            $premier = null;
            $dernier = null;

            foreach ($trace['valeurs'] as $rang => $valeur) {
                if ($valeur === null) {
                    $ouvert = false;

                    continue;
                }

                $chemin .= ($ouvert ? 'L' : 'M').self::n($x($rang)).' '.self::n($y((float) $valeur, $trace['axe'])).' ';
                $premier ??= $rang;
                $dernier = $rang;
                $ouvert = true;
            }

            if ($chemin === '' || $premier === null || $dernier === null) {
                continue;
            }

            if ($trace['forme'] === 'area') {
                $dessin .= '<path d="'.$chemin.'L'.self::n($x($dernier)).' '.self::n($y(0.0, $trace['axe'])).' L'.self::n($x($premier)).' '.self::n($y(0.0, $trace['axe'])).' Z" fill="'.$trace['couleur'].'" fill-opacity=".16" stroke="none"/>';
            }

            $dessin .= '<path d="'.rtrim($chemin).'" fill="none" stroke="'.$trace['couleur'].'" stroke-width="'.($trace['role'] === SerieDuGraphique::ROLE_TENDANCE ? '2.6' : '2.2').'" stroke-linejoin="round" stroke-linecap="round"'.($trace['pointilles'] ? ' stroke-dasharray="6 4"' : '').'/>';
        }

        foreach ($barres as $rang => $barre) {
            $dessin .= self::colonne($rang, $barre, $traces, $serie, $graphique, $cliquable, $euros, $gauche + $colonne * $rang, $colonne, $hauteurDuTrace, $x($rang), $y, $nombre);
        }

        $plusLong = max(array_map(fn (array $etiquette): int => mb_strlen($etiquette[0]), $etiquettes) ?: [3]);
        $pas = max(1, (int) ceil(($plusLong * 6.6 + 8) / max(1.0, $colonne)));

        foreach ($barres as $rang => $barre) {
            [$premiere, $seconde] = $etiquettes[$rang];

            if ($rang % $pas !== 0 && ! ($seconde !== null && $pas === 1)) {
                continue;
            }

            $dessin .= '<text x="'.self::n($x($rang)).'" y="'.self::n(self::HAUT + $hauteurDuTrace + 16).'" text-anchor="middle" font-size="11.5" fill="'.($barre->aVenir ? 'var(--periode-doux)' : 'var(--periode-texte)').'"'.($barre->enCours ? ' font-weight="700"' : '').'>'.self::echapper($premiere).'</text>';

            if ($seconde !== null && $rang % $pas === 0) {
                $dessin .= '<text x="'.self::n($x($rang)).'" y="'.self::n(self::HAUT + $hauteurDuTrace + 30).'" text-anchor="middle" font-size="11" fill="var(--periode-doux)">'.self::echapper($seconde).'</text>';
            }
        }

        return '<svg class="piliqi-graphiques__periode-dessin" data-periode="dessin" viewBox="0 0 '.self::n(self::LARGEUR).' '.self::n($hauteur).'" role="group" aria-label="'.self::echapper($titre).'">'.$dessin.'</svg>';
    }

    /**
     * Les couleurs et les formes des séries de la carte, dans l'ordre des
     * jeux de la série : chaque série de la carte reçoit sa couleur par son
     * rang parmi elles ; l'an dernier est gris, en pointillés quand il se
     * dessine en courbe, en barres grises à côté des barres sinon ; la
     * tendance est en encre ; les barres de l'écart sont vertes ou rouges
     * selon leur signe.
     *
     * @return list<Trace>
     */
    private static function traces(SerieDuGraphique $serie, TypeDeGraphique $type, bool $cumul): array
    {
        $traces = [];
        $rangDeLaSerie = 0;
        $principale = null;

        foreach ($serie->jeux as $jeu) {
            $role = $jeu['role'];
            $propre = $jeu['en_ligne'] && ! $cumul;
            $forme = match (true) {
                $role === SerieDuGraphique::ROLE_ECART => 'bar',
                $role === SerieDuGraphique::ROLE_TENDANCE => 'line',
                $role === SerieDuGraphique::ROLE_COMPARAISON => ($cumul || $principale === 'line' || $principale === 'area') ? 'line' : 'bar',
                $propre => 'line',
                $type === TypeDeGraphique::Lignes => 'line',
                $type === TypeDeGraphique::Aires => 'area',
                $cumul => 'line',
                default => 'bar',
            };

            if ($role === SerieDuGraphique::ROLE_SERIE) {
                $principale ??= $forme;
            }

            $cle = match ($role) {
                SerieDuGraphique::ROLE_COMPARAISON => 'avant',
                SerieDuGraphique::ROLE_TENDANCE => 'encre',
                SerieDuGraphique::ROLE_ECART => 'hausse',
                default => 's'.(($rangDeLaSerie % self::SERIES) + 1),
            };

            if ($role === SerieDuGraphique::ROLE_SERIE) {
                $rangDeLaSerie++;
            }

            $traces[] = [
                'valeurs' => $jeu['valeurs'],
                'unite' => $jeu['unite'],
                'forme' => $forme,
                'cle' => $cle,
                'couleur' => 'var(--periode-'.$cle.')',
                'pointilles' => $jeu['pointilles'] || ($role === SerieDuGraphique::ROLE_COMPARAISON && $forme !== 'bar'),
                'axe' => $jeu['axe_de_droite'] ? 'd' : 'g',
                'role' => $role,
                'signe' => $role === SerieDuGraphique::ROLE_ECART,
                'libelle' => $jeu['libelle'],
            ];
        }

        return $traces;
    }

    /**
     * La légende de la carte : une pastille par série (un carré pour une
     * barre, un trait pour une courbe ou une aire, des tirets pour l'an
     * dernier en courbe), l'an dernier en gris, la tendance en encre ; en
     * écart en %, ce que disent le vert et le rouge. Une carte à une seule
     * série n'a pas de légende : son titre la nomme.
     *
     * @return list<array{libelle: string, forme: 'carre'|'trait'|'tirets', cle: string}>
     */
    public static function legende(SerieDuGraphique $serie, TypeDeGraphique $type, bool $cumul): array
    {
        $legende = [];
        $traces = self::traces($serie, $type, $cumul);

        foreach ($traces as $trace) {
            if ($trace['role'] === SerieDuGraphique::ROLE_ECART) {
                return [
                    ['libelle' => 'Au-dessus de 0 : en hausse sur un an', 'forme' => 'carre', 'cle' => 'hausse'],
                    ['libelle' => 'En dessous de 0 : en baisse', 'forme' => 'carre', 'cle' => 'baisse'],
                ];
            }
        }

        foreach ([SerieDuGraphique::ROLE_SERIE, SerieDuGraphique::ROLE_COMPARAISON, SerieDuGraphique::ROLE_TENDANCE] as $role) {
            foreach ($traces as $trace) {
                if ($trace['role'] === $role) {
                    $legende[] = ['libelle' => $trace['libelle'], 'forme' => $trace['forme'] === 'bar' ? 'carre' : ($trace['pointilles'] ? 'tirets' : 'trait'), 'cle' => $trace['cle']];
                }
            }
        }

        return count($legende) < 2 ? [] : $legende;
    }

    /**
     * L'étiquette de chaque barre : la première ligne (« janv. », « T1 2026 »,
     * « 2026 », « 14 »), et, sous un mois, l'année quand il est le premier de
     * la carte ou un janvier.
     *
     * @param  list<Barre>  $barres
     * @return list<array{0: string, 1: string|null}>
     */
    private static function etiquettes(array $barres): array
    {
        $etiquettes = [];

        foreach ($barres as $rang => $barre) {
            if ($barre->niveau === NiveauDeLaBarre::Mois) {
                $mois = Mois::duJour($barre->premierJour);
                $etiquettes[] = [Mois::nomCourt($mois), ($rang === 0 || Mois::numero($mois) === 1) ? (string) Mois::annee($mois) : null];

                continue;
            }

            $etiquettes[] = [$barre->etiquette, null];
        }

        return $etiquettes;
    }

    /**
     * La colonne d'une barre : sa zone sensible, les points de ses courbes
     * et, si la barre est cliquable, ses gestes.
     *
     * @param  list<Trace>  $traces
     * @param  Closure(int): string  $euros
     * @param  Closure(float, string=): float  $y  l'ordonnée d'une valeur sur l'axe de gauche ('g') ou de droite ('d')
     */
    private static function colonne(int $rang, Barre $barre, array $traces, SerieDuGraphique $serie, string $graphique, bool $cliquable, Closure $euros, float $depart, float $largeur, float $hauteurDuTrace, float $centre, Closure $y, int $nombre): string
    {
        $clic = $cliquable && ! $barre->aVenir;
        $principale = $traces[0] ?? null;
        $valeur = $principale === null ? null : ($principale['valeurs'][$rang] ?? null);
        $dit = $barre->intitule.' : '.($barre->aVenir || $principale === null || $valeur === null ? ($barre->aVenir ? 'à venir' : '–') : self::texte($valeur, $principale['unite'], $euros));
        $libelle = $dit.($clic ? ($barre->niveau === NiveauDeLaBarre::Jour ? ' — ouvrir la liste de ses documents' : ' — cliquer pour voir le détail') : '');
        $gestes = '';

        if ($clic) {
            $arguments = sprintf('&#039;%s&#039;, &#039;%s&#039;', self::echapper($graphique), self::echapper($barre->jeton()));
            $gestes = sprintf(' wire:click="choisirLaBarre(%s)"', $arguments)
                .sprintf(' x-on:keydown.enter.prevent="$wire.choisirLaBarre(%s)"', $arguments)
                .sprintf(' x-on:keydown.space.prevent="$wire.choisirLaBarre(%s)"', $arguments);
        }

        $dessin = '<g class="colonne'.($clic ? ' clic' : '').'" data-rang="'.$rang.'" data-info-bulle="'.self::echapper(self::infoBulle($rang, $barre, $traces, $serie, $euros)).'"'
            .($clic ? ' tabindex="0" role="button"' : ' role="img"').' aria-label="'.self::echapper($libelle).'"'.$gestes.'>';
        $dessin .= '<rect class="fond" x="'.self::n($depart).'" y="'.self::n(self::HAUT).'" width="'.self::n($largeur).'" height="'.self::n($hauteurDuTrace).'" rx="4"/>';

        foreach ($traces as $trace) {
            $valeurDuPoint = $trace['valeurs'][$rang] ?? null;

            if ($trace['forme'] === 'bar' || $valeurDuPoint === null || $trace['role'] === SerieDuGraphique::ROLE_COMPARAISON) {
                continue;
            }

            $rayon = $trace['role'] === SerieDuGraphique::ROLE_TENDANCE ? 2.8 : ($nombre > self::POINTS_AU_PLUS ? 0.0 : 3.2);
            $dessin .= '<circle cx="'.self::n($centre).'" cy="'.self::n($y((float) $valeurDuPoint, $trace['axe'])).'" r="'.self::n($rayon).'" fill="'.$trace['couleur'].'" stroke="var(--periode-carte)" stroke-width="2"/>';
        }

        return $dessin.'</g>';
    }

    /**
     * L'info-bulle d'une barre, en JSON : son intitulé, puis une ligne par
     * série, avec la pastille de sa couleur ; en écart, l'écart et les deux
     * montants qu'il compare.
     *
     * @param  list<Trace>  $traces
     * @param  Closure(int): string  $euros
     */
    private static function infoBulle(int $rang, Barre $barre, array $traces, SerieDuGraphique $serie, Closure $euros): string
    {
        $lignes = [];

        if ($barre->aVenir) {
            $lignes[] = ['couleur' => '', 'texte' => 'À venir'];
        } elseif ($serie->ecartSurUnAn !== null) {
            $ligne = $serie->ecartSurUnAn['lignes'][$rang] ?? ['actuel' => null, 'precedent' => null];
            $unite = $serie->ecartSurUnAn['unite'];
            $ecart = $traces[0]['valeurs'][$rang] ?? null;
            $lignes[] = ['couleur' => '', 'texte' => 'Écart sur un an : '.($ecart === null ? '–' : self::texte($ecart, SerieDuGraphique::POURCENT, $euros))];
            $lignes[] = ['couleur' => '', 'texte' => 'Cette période : '.($ligne['actuel'] === null ? '–' : self::texte($ligne['actuel'], $unite, $euros))];
            $lignes[] = ['couleur' => '', 'texte' => 'Un an plus tôt : '.($ligne['precedent'] === null ? '–' : self::texte($ligne['precedent'], $unite, $euros))];
        } else {
            foreach ($traces as $trace) {
                $valeur = $trace['valeurs'][$rang] ?? null;

                if ($valeur !== null) {
                    $lignes[] = ['couleur' => $trace['couleur'], 'texte' => $trace['libelle'].' : '.self::texte($valeur, $trace['unite'], $euros)];
                }
            }

            $precision = $serie->infoBulles[$rang] ?? null;

            if ($precision !== null) {
                $lignes[] = ['couleur' => '', 'texte' => $precision];
            }
        }

        return json_encode(['titre' => $barre->intitule, 'lignes' => $lignes], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Cette barre est-elle un écart sans année d'avant (« – ») ?
     *
     * @param  list<Trace>  $traces
     */
    private static function sansEcart(array $traces, int $rang): bool
    {
        foreach ($traces as $trace) {
            if ($trace['role'] === SerieDuGraphique::ROLE_ECART && ($trace['valeurs'][$rang] ?? null) === null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Une valeur telle qu'on la lit : un montant en euros, un nombre entier
     * ou un écart signé en %.
     *
     * @param  Closure(int): string  $euros
     */
    private static function texte(int $valeur, string $unite, Closure $euros): string
    {
        return match ($unite) {
            SerieDuGraphique::NOMBRE => self::entier($valeur),
            SerieDuGraphique::POURCENT => ($valeur > 0 ? '+' : '').CalculDesSeries::pourcentage($valeur),
            default => $euros($valeur),
        };
    }

    private static function entier(int $valeur): string
    {
        return number_format($valeur, 0, ',', "\u{202F}");
    }

    /**
     * Les graduations d'un axe : « 60 k€ », « 12,5 M€ », « 500 € », « +20 % »,
     * « 1 200 » pour un nombre.
     */
    private static function graduation(float $valeur, string $unite): string
    {
        if ($unite === SerieDuGraphique::POURCENT) {
            $pourcent = abs($valeur) / 100;

            return ($valeur > 0 ? '+' : ($valeur < 0 ? '−' : '')).rtrim(rtrim(number_format($pourcent, 2, ',', "\u{202F}"), '0'), ',')."\u{00A0}%";
        }

        if ($unite === SerieDuGraphique::NOMBRE) {
            return number_format($valeur, 0, ',', "\u{202F}");
        }

        $euros = abs($valeur) / 100;
        $signe = $valeur < 0 ? '−' : '';

        if ($euros >= 1_000_000) {
            return $signe.rtrim(rtrim(number_format($euros / 1_000_000, 1, ',', "\u{202F}"), '0'), ',')."\u{00A0}M€";
        }

        if ($euros >= 1000) {
            return $signe.rtrim(rtrim(number_format($euros / 1000, 1, ',', "\u{202F}"), '0'), ',')."\u{00A0}k€";
        }

        return $signe.number_format(round($euros), 0, ',', "\u{202F}")."\u{00A0}€";
    }

    /**
     * L'échelle d'un axe : du plus petit de 0 et des valeurs au plus grand,
     * en pas « ronds » (1, 2, 2,5, 5 ou 10 fois une puissance de dix), au
     * plus quatre intervalles environ.
     *
     * @param  list<int|float>  $valeurs
     * @return array{min: float, max: float, pas: float}
     */
    private static function echelle(array $valeurs): array
    {
        $minimum = min(0, ...($valeurs === [] ? [0] : $valeurs));
        $maximum = max(0, ...($valeurs === [] ? [0] : $valeurs));

        if ($maximum === 0 && $minimum === 0) {
            $maximum = 1;
        }

        $pas = self::pasRond(($maximum - $minimum) / self::NOMBRE_DE_GRADUATIONS);

        return ['min' => floor($minimum / $pas) * $pas, 'max' => ceil($maximum / $pas) * $pas, 'pas' => $pas];
    }

    private static function pasRond(float $ecart): float
    {
        $puissance = 10 ** floor(log10($ecart));
        $facteur = $ecart / $puissance;

        return match (true) {
            $facteur <= 1 => 1.0,
            $facteur <= 2 => 2.0,
            $facteur <= 2.5 => 2.5,
            $facteur <= 5 => 5.0,
            default => 10.0,
        } * $puissance;
    }

    /**
     * @param  list<Trace>  $traces
     * @return list<int>
     */
    private static function valeursDe(array $traces, string $axe): array
    {
        $valeurs = [];

        foreach ($traces as $trace) {
            if ($trace['axe'] === $axe) {
                foreach ($trace['valeurs'] as $valeur) {
                    if ($valeur !== null) {
                        $valeurs[] = $valeur;
                    }
                }
            }
        }

        return $valeurs;
    }

    /**
     * @param  list<Trace>  $traces
     */
    private static function uniteDe(array $traces, string $axe): string
    {
        foreach ($traces as $trace) {
            if ($trace['axe'] === $axe) {
                return $trace['unite'];
            }
        }

        return SerieDuGraphique::EUROS;
    }

    /**
     * L'ordonnée d'une valeur sur l'axe : le haut de l'axe tout en haut, son
     * bas tout en bas.
     *
     * @param  array{min: float, max: float, pas: float}  $echelle
     */
    private static function position(float $valeur, array $echelle, float $hauteurDuTrace): float
    {
        return self::HAUT + ($echelle['max'] - $valeur) / ($echelle['max'] - $echelle['min']) * $hauteurDuTrace;
    }

    private static function n(float $nombre): string
    {
        $texte = rtrim(rtrim(number_format($nombre, 1, '.', ''), '0'), '.');

        return $texte === '' || $texte === '-0' ? '0' : $texte;
    }

    private static function echapper(string $texte): string
    {
        return htmlspecialchars($texte, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
