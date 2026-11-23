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
 * écrit ; le reste du dessin, dont les étiquettes des mois, l'est.
 *
 * Choisir un mois ne passe jamais par le serveur (décision de l'éditeur du
 * 23/11/2026) : le dessin est écrit une seule fois, avec les attributs du
 * mois de départ, et chaque colonne (le groupe `g` de classe `colonne`)
 * reçoit, en plus des attributs de la maquette, le clic, Entrée et Espace,
 * qui placent `mois` (la donnée Alpine de la carte, voir
 * ecart-des-prix-d-achat.blade.php) sur le rang de son mois, et un `x-bind`
 * pour chaque attribut que ce choix change (voir
 * DessinDeLEcartDesPrixDAchat::ceQueLeChoixChange()) : `aria-pressed`, le
 * cadre et l'étiquette du mois suivent `mois` dans la page. Le rang d'une
 * colonne est celui de son mois, même quand une colonne qui la précède n'est
 * pas écrite. Ces attributs ne sont pas dans l'arbre comparé à la maquette.
 *
 * @phpstan-import-type Feuille from DessinDeLEcartDesPrixDAchat
 * @phpstan-import-type Groupe from DessinDeLEcartDesPrixDAchat
 * @phpstan-import-type Variation from DessinDeLEcartDesPrixDAchat
 * @phpstan-import-type VariationsDUneColonne from DessinDeLEcartDesPrixDAchat
 */
final class SvgDeLEcartDesPrixDAchat
{
    /** La classe du `svg`, que la feuille des graphiques dessine. */
    public const CLASSE = 'piliqi-graphiques__ecart-dessin';

    /** Ce que la maquette écrit pour une position qu'elle ne sait pas calculer. */
    private const ILLISIBLE = 'NaN';

    /** La donnée Alpine de la carte qui porte le rang du mois choisi (voir ecart-des-prix-d-achat.blade.php). */
    private const MOIS_CHOISI = 'mois';

    /**
     * Le `svg` en entier, prêt à être écrit dans la page.
     *
     * @param  list<Feuille|Groupe>  $enfants  l'arbre du dessin (voir DessinDeLEcartDesPrixDAchat::noeuds()), celui du mois de départ
     * @param  list<VariationsDUneColonne>  $variations  ce que le choix d'un mois change dans chaque colonne (voir DessinDeLEcartDesPrixDAchat::ceQueLeChoixChange())
     */
    public static function ecrire(array $enfants, array $variations, string $cadre, string $libelle): string
    {
        $colonne = 0;
        $contenu = '';

        foreach ($enfants as $noeud) {
            $contenu .= self::noeud($noeud, $variations, $colonne);
        }

        return '<svg class="'.self::CLASSE.'" data-ecart="graphique" viewBox="'.self::echapper($cadre).'" role="group" aria-label="'.self::echapper($libelle).'">'.$contenu.'</svg>';
    }

    /**
     * Un élément de la racine : une graduation, ou une colonne, qui prend le
     * rang suivant même quand elle n'est pas écrite.
     *
     * @param  Feuille|Groupe  $noeud
     * @param  list<VariationsDUneColonne>  $variations
     */
    private static function noeud(array $noeud, array $variations, int &$colonne): string
    {
        if ($noeud['t'] !== 'g') {
            return self::element($noeud, null, [], []);
        }

        $rang = $colonne++;

        return self::element($noeud, $rang, $variations[$rang]['a'] ?? [], $variations[$rang]['f'] ?? []);
    }

    /**
     * Un élément et ses enfants ; rien quand un de ses attributs est
     * illisible.
     *
     * @param  Feuille|Groupe  $noeud
     * @param  int|null  $rang  le rang du mois de la colonne de l'élément, null hors d'une colonne
     * @param  array<string, Variation>  $changes  les attributs de l'élément que le choix de ce mois change
     * @param  array<int, array<string, Variation>>  $changesDesEnfants  ceux de ses enfants, par rang dans l'élément
     */
    private static function element(array $noeud, ?int $rang, array $changes, array $changesDesEnfants): string
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

        if ($rang !== null) {
            $attributs .= $noeud['t'] === 'g' ? self::gestes($rang) : '';

            foreach ($changes as $nom => $variation) {
                $attributs .= self::liaison($nom, $variation, $rang);
            }
        }

        $contenu = $noeud['x'] === null ? '' : self::echapper($noeud['x']);

        foreach ($noeud['f'] as $position => $enfant) {
            $contenu .= self::element($enfant, $rang, $changesDesEnfants[$position] ?? [], []);
        }

        return '<'.$noeud['t'].$attributs.'>'.$contenu.'</'.$noeud['t'].'>';
    }

    /**
     * Ce qui fait d'une colonne un geste de la page : le clic, Entrée et
     * Espace (l'action par défaut est annulée) placent `mois` sur son rang.
     * Aucun n'appelle le serveur.
     */
    private static function gestes(int $rang): string
    {
        $choix = self::MOIS_CHOISI.' = '.$rang;

        return ' x-on:click="'.$choix.'" x-on:keydown.enter.prevent="'.$choix.'" x-on:keydown.space.prevent="'.$choix.'"';
    }

    /**
     * Un attribut que le choix du mois change : la page lui donne l'une de
     * ses deux valeurs selon que `mois` est le rang de cette colonne.
     *
     * @param  Variation  $variation
     */
    private static function liaison(string $nom, array $variation, int $rang): string
    {
        $expression = self::MOIS_CHOISI.' === '.$rang.' ? '.self::litteral($variation['choisi']).' : '.self::litteral($variation['autre']);

        return ' x-bind:'.self::echapper($nom).'="'.self::echapper($expression).'"';
    }

    /**
     * Une chaîne de JavaScript entre apostrophes.
     */
    private static function litteral(string $texte): string
    {
        return "'".addcslashes($texte, "'\\")."'";
    }

    private static function echapper(string $texte): string
    {
        return htmlspecialchars($texte, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
