<?php

namespace App\Domain\TableauDeBord\Support;

/**
 * Le `svg` de la carte « Écart des prix d'achat » (décision de l'éditeur du
 * 21/11/2026, D76, B7) : l'écriture, en HTML, de l'arbre du dessin que
 * DessinDeLEcartDesPrixDAchat calcule (voir cette classe pour les
 * coordonnées, écrites comme la maquette les écrit).
 *
 * Tout attribut et tout texte passent par htmlspecialchars. Un élément dont
 * un attribut vaut « NaN » (le jeu d'achats n'a aucun écart nulle part : la
 * maquette divise par zéro, et le navigateur ignore l'élément) n'est pas
 * écrit ; le reste du dessin, dont les étiquettes des mois, l'est. Chaque
 * colonne (le groupe `g` de classe `colonne`) reçoit, en plus des attributs
 * de la maquette, ce qui en fait un geste de la grille : le clic, puis
 * Entrée et Espace (l'action par défaut est annulée) appellent
 * `choisirLeMois` avec le graphique et le rang du mois. Ces attributs ne
 * sont pas dans l'arbre comparé à la maquette.
 *
 * @phpstan-import-type Feuille from DessinDeLEcartDesPrixDAchat
 * @phpstan-import-type Groupe from DessinDeLEcartDesPrixDAchat
 */
final class SvgDeLEcartDesPrixDAchat
{
    /** La classe du `svg`, que la feuille des graphiques dessine. */
    public const CLASSE = 'piliqi-graphiques__ecart-dessin';

    /** Ce que la maquette écrit pour une position qu'elle ne sait pas calculer. */
    private const ILLISIBLE = 'NaN';

    /**
     * Le `svg` en entier, prêt à être écrit dans la page.
     *
     * @param  list<Feuille|Groupe>  $enfants  l'arbre du dessin (voir DessinDeLEcartDesPrixDAchat::noeuds())
     * @param  string  $graphique  la valeur du graphique, que chaque colonne passe à son geste
     */
    public static function ecrire(array $enfants, string $cadre, string $libelle, string $graphique): string
    {
        $colonne = 0;
        $contenu = '';

        foreach ($enfants as $noeud) {
            $contenu .= self::noeud($noeud, $graphique, $colonne);
        }

        return '<svg class="'.self::CLASSE.'" data-ecart="graphique" viewBox="'.self::echapper($cadre).'" role="group" aria-label="'.self::echapper($libelle).'">'.$contenu.'</svg>';
    }

    /**
     * Un élément et ses enfants ; rien quand un de ses attributs est
     * illisible.
     *
     * @param  Feuille|Groupe  $noeud
     */
    private static function noeud(array $noeud, string $graphique, int &$colonne): string
    {
        foreach ($noeud['a'] as $valeur) {
            if (str_contains($valeur, self::ILLISIBLE)) {
                return '';
            }
        }

        $attributs = '';

        foreach ($noeud['a'] as $nom => $valeur) {
            $attributs .= ' '.$nom.'="'.self::echapper($valeur).'"';
        }

        if ($noeud['t'] === 'g') {
            $attributs .= self::geste($graphique, $colonne++);
        }

        $contenu = $noeud['x'] === null ? '' : self::echapper($noeud['x']);

        foreach ($noeud['f'] as $enfant) {
            $contenu .= self::noeud($enfant, $graphique, $colonne);
        }

        return '<'.$noeud['t'].$attributs.'>'.$contenu.'</'.$noeud['t'].'>';
    }

    /**
     * Les attributs qui font d'une colonne un geste de la grille
     * (`choisirLeMois`, voir GrilleDesGraphiques) : le clic, Entrée et
     * Espace, qui passent le graphique et le rang du mois. Les gestes sont
     * écrits tels quels, pour que l'inventaire des actions les lise
     * (voir ActionsDeLInterface).
     */
    private static function geste(string $graphique, int $mois): string
    {
        $arguments = sprintf('&#039;%s&#039;, %d', self::echapper($graphique), $mois);

        return sprintf(' wire:click="choisirLeMois(%s)"', $arguments)
            .sprintf(' x-on:keydown.enter.prevent="$wire.choisirLeMois(%s)"', $arguments)
            .sprintf(' x-on:keydown.space.prevent="$wire.choisirLeMois(%s)"', $arguments);
    }

    private static function echapper(string $texte): string
    {
        return htmlspecialchars($texte, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
