<?php

namespace Tests\Fixtures\EcartDesPrixDAchat;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Masterminds\HTML5;
use RuntimeException;

/**
 * Lit la carte « Écart des prix d'achat » dans le HTML d'une page (celui de
 * Livewire ou le `outerHTML` du navigateur) comme la maquette est lue pour
 * produire `verite.json` (voir le relevé de la maquette) : le texte, les
 * classes et les attributs de chaque élément que la carte montre, rangés
 * dans la même structure, clés dans le même ordre.
 *
 * Ce que la page écrit autrement que la maquette, et que la lecture traduit
 * ou laisse à part (décisions D76 de la spécification) :
 *
 *  - les classes de la feuille des graphiques portent le préfixe
 *    `piliqi-graphiques__ecart-` : il est retiré ;
 *  - les attributs `wire:` et `x-` (les gestes de la grille) ne sont pas dans
 *    l'arbre comparé à la maquette : ils sont ignorés dans le dessin ;
 *  - le contrôle de type de l'en-tête s'écrit « Barres » (B13, point 4) au
 *    lieu de « Barres ▾ » ;
 *  - la croix est l'icône de Filament, sans le texte « × » : seul son
 *    libellé accessible est lu, son texte vaut null ;
 *  - le mois choisi (`mois`) se lit sur la colonne du dessin dont
 *    `aria-pressed` vaut « true », null quand la page n'a pas de dessin ;
 *  - la page n'écrit que l'affichage choisi : par mois, ni les cartes de
 *    chiffres des douze mois (`stats` vaut null) ; sur douze mois, ni le
 *    dessin ni ses légendes (`dessin` et `legendes` valent null), ni le
 *    tableau « Voir les chiffres » ;
 *  - le texte d'un lien « Voir… » ouvert est celui de son `x-text`.
 *
 * Le HTML est lu par un analyseur HTML5 (les commentaires de Livewire et le
 * texte entre les éléments sont ignorés).
 */
final class LectureDeLaPage
{
    public const PREFIXE_DES_CLASSES = 'piliqi-graphiques__ecart-';

    private function __construct(
        private readonly DOMDocument $document,
        private readonly DOMXPath $chemin,
        private readonly DOMElement $carte,
    ) {}

    /**
     * La carte de ce graphique dans cette page.
     *
     * @return array<string, mixed>
     */
    public static function lire(string $html, string $graphique = 'ecart_des_prix_d_achat'): array
    {
        $document = (new HTML5(['disable_html_ns' => true]))->loadHTML('<!DOCTYPE html><html><body>'.$html.'</body></html>');
        $chemin = new DOMXPath($document);
        $cartes = $chemin->query('//*[@data-graphique="'.$graphique.'"]');

        if ($cartes === false || $cartes->length !== 1 || ! $cartes->item(0) instanceof DOMElement) {
            throw new RuntimeException('La page ne porte pas exactement une carte « '.$graphique.' ».');
        }

        return (new self($document, $chemin, $cartes->item(0)))->lecture();
    }

    /**
     * Le premier écart (chemin, valeur lue, valeur attendue) entre cette
     * lecture de la page et le relevé de la maquette pour cet affichage et
     * ce mois choisi, une fois ôtés les écarts que la classe dit (voir son
     * commentaire) ; null quand la page est la maquette.
     *
     * @param  array<string, mixed>  $lue  ce que lire() a rendu
     * @param  'avant'|'debut'  $affichage
     */
    public static function premierEcartAvecLaMaquette(array $lue, string $affichage, int $mois): ?string
    {
        $attendu = LectureDesFixtures::verite()['etats'][$affichage][$mois];
        $attendu['boutons'][0] = 'Barres';
        $attendu['croix']['texte'] = null;

        if ($affichage === 'avant') {
            $attendu['stats'] = null;
        } else {
            $attendu['mois'] = null;
            $attendu['legendes'] = null;
            $attendu['dessin'] = null;
        }

        return LectureDesFixtures::premierEcart($lue, $attendu);
    }

    /**
     * @return array<string, mixed>
     */
    private function lecture(): array
    {
        $vueDuMois = $this->peutLire('vue-mois');
        $vueDeLaPeriode = $this->peutLire('vue-periode');
        $menu = $this->un('menu');
        $options = [];
        $valeur = null;

        foreach ($this->enfants($menu, 'option') as $option) {
            $options[] = ['value' => $option->getAttribute('value'), 'texte' => $this->texte($option)];

            if ($option->hasAttribute('selected')) {
                $valeur = $option->getAttribute('value');
            }
        }

        return [
            'mode' => $valeur,
            'mois' => $vueDuMois ? $this->moisDuDessin() : null,
            'titreCarte' => $this->texteDe($this->premier('.//*[@data-titre]')),
            'boutons' => [
                $this->texteDe($this->premier('.//select[@data-geste="type"]//option[@selected]')),
                $this->texteDe($this->premier('.//*[@data-geste="monter"]')),
                $this->texteDe($this->premier('.//*[@data-geste="descendre"]')),
            ],
            'croix' => ['texte' => null, 'ariaLabel' => $this->libelleDeLaCroix()],
            'etiquette' => $this->texteDe($this->un('etiquette')),
            'options' => $options,
            'valeurMenu' => $valeur,
            'moisChoisi' => $this->texteDe($this->un('mois-choisi')),
            'grand' => $this->classeEtTexte('grand'),
            'pastille' => $this->classeEtTexte('pastille'),
            'detail' => $this->texteDe($this->un('detail')),
            'vues' => ['moisCachee' => ! $vueDuMois, 'periodeCachee' => ! $vueDeLaPeriode],
            'legendes' => $vueDuMois ? ['plus' => $this->texteDe($this->un('legende-plus')), 'moins' => $this->texteDe($this->un('legende-moins')), 'net' => $this->texteDe($this->un('legende-net'))] : null,
            'methode' => $this->texteDe($this->un('methode')),
            'dessin' => $vueDuMois ? $this->dessin() : null,
            'calcul' => [
                'lien' => $this->lien('voir-calcul'),
                'titre' => $this->texteDe($this->un('calcul-titre')),
                'classeDefiler' => $this->classes($this->premier('.//*[@data-ecart="calcul"]//*[contains(concat(" ", normalize-space(@class), " "), " '.self::PREFIXE_DES_CLASSES.'defiler ")]')),
                'tete' => $this->entetes($this->un('calcul-tete')),
                'lignes' => $this->lignes($this->un('calcul-corps')),
                'notes' => array_map($this->texte(...), $this->enfants($this->un('calcul-notes'))),
            ],
            'stats' => $vueDeLaPeriode ? [
                'nomBase' => $this->texteDe($this->precedent($this->un('stat-base'))),
                'base' => $this->texteDe($this->un('stat-base')),
                'nomCout' => $this->texteDe($this->precedent($this->un('stat-cout'))),
                'cout' => $this->texteDe($this->un('stat-cout')),
            ] : null,
            'chiffres' => $vueDeLaPeriode
                ? ['lien' => $this->lien('voir-chiffres'), 'tete' => null, 'lignes' => null, 'note' => null]
                : ['lien' => $this->lien('voir-chiffres'), 'tete' => $this->entetes($this->un('chiffres-tete')), 'lignes' => $this->lignes($this->un('chiffres-corps')), 'note' => $this->texteDe($this->un('chiffres-note'))],
        ];
    }

    /**
     * Le mois choisi, lu sur la colonne du dessin dont `aria-pressed` vaut
     * « true » ; null quand aucune colonne ne l'est.
     */
    private function moisDuDessin(): ?int
    {
        $colonnes = $this->chemin->query('.//*[@data-ecart="graphique"]/*[@role="button"]', $this->carte);

        if ($colonnes === false) {
            return null;
        }

        foreach ($colonnes as $rang => $colonne) {
            if ($colonne instanceof DOMElement && $colonne->getAttribute('aria-pressed') === 'true') {
                return $rang;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function dessin(): array
    {
        $graphique = $this->un('graphique');

        return [
            'ariaLabel' => $graphique->getAttribute('aria-label'),
            'viewBox' => $graphique->getAttribute('viewbox') !== '' ? $graphique->getAttribute('viewbox') : $graphique->getAttribute('viewBox'),
            'enfants' => array_map($this->noeud(...), $this->enfants($graphique)),
        ];
    }

    /**
     * Un élément du dessin comme la maquette le lit : sa balise, ses
     * attributs (sans les gestes de la grille), son texte quand il n'a pas
     * d'enfant, ses enfants.
     *
     * @return array{t: string, a: array<string, string>, x: string|null, f: list<array<string, mixed>>}
     */
    private function noeud(DOMElement $element): array
    {
        $attributs = [];

        foreach ($element->attributes ?? [] as $attribut) {
            if (! str_starts_with($attribut->nodeName, 'wire:') && ! str_starts_with($attribut->nodeName, 'x-')) {
                $attributs[$attribut->nodeName] = $attribut->nodeValue ?? '';
            }
        }

        $enfants = array_map($this->noeud(...), $this->enfants($element));

        return ['t' => strtolower($element->nodeName), 'a' => $attributs, 'x' => $enfants === [] ? (string) $element->textContent : null, 'f' => $enfants];
    }

    /**
     * Le texte d'un lien « Voir… » fermé, et celui qu'il prend ouvert (le
     * `x-text` du lien).
     *
     * @return array{cache: bool, texte: string, texteOuvert: string|null}
     */
    private function lien(string $nom): array
    {
        $lien = $this->un($nom);
        $ouvert = null;

        foreach ($this->chemin->query('.//*[@x-text]', $lien) ?: [] as $texte) {
            if ($texte instanceof DOMElement && preg_match("/^\\w+ \\? '(.*)' : '(.*)'\$/su", $texte->getAttribute('x-text'), $parties) === 1) {
                $ouvert = $parties[1];
            }
        }

        return ['cache' => $lien->hasAttribute('hidden'), 'texte' => $this->texte($lien), 'texteOuvert' => $ouvert];
    }

    /**
     * @return list<string>
     */
    private function entetes(DOMElement $ligne): array
    {
        return array_map($this->texte(...), $this->enfants($ligne, 'th'));
    }

    /**
     * @return list<array{classe: string, cellules: list<array{t: string, c: string, colspan: int}>}>
     */
    private function lignes(DOMElement $corps): array
    {
        $lignes = [];

        foreach ($this->enfants($corps, 'tr') as $ligne) {
            $cellules = [];

            foreach ($this->enfants($ligne, 'td') as $cellule) {
                $cellules[] = ['t' => (string) $cellule->textContent, 'c' => $this->classes($cellule), 'colspan' => $cellule->hasAttribute('colspan') ? (int) $cellule->getAttribute('colspan') : 1];
            }

            $lignes[] = ['classe' => $this->classes($ligne), 'cellules' => $cellules];
        }

        return $lignes;
    }

    /**
     * @return array{texte: string, classe: string}
     */
    private function classeEtTexte(string $nom): array
    {
        $element = $this->un($nom);

        return ['texte' => (string) $element->textContent, 'classe' => $this->classes($element)];
    }

    /**
     * Les classes d'un élément, sans le préfixe de la feuille des
     * graphiques, séparées par une espace.
     */
    private function classes(DOMElement $element): string
    {
        $classes = preg_split('/\s+/', trim($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(fn (string $classe): string => str_starts_with($classe, self::PREFIXE_DES_CLASSES) ? substr($classe, strlen(self::PREFIXE_DES_CLASSES)) : $classe, $classes));
    }

    private function libelleDeLaCroix(): string
    {
        $croix = $this->premier('.//*[@data-geste="retirer"]');
        $libelle = $this->chemin->query('.//*[contains(concat(" ", normalize-space(@class), " "), " sr-only ")]', $croix);

        return $libelle !== false && $libelle->length > 0 && $libelle->item(0) !== null ? $this->texte($libelle->item(0)) : $croix->getAttribute('aria-label');
    }

    private function precedent(DOMElement $element): DOMElement
    {
        $voisin = $element->previousSibling;

        while ($voisin !== null && ! $voisin instanceof DOMElement) {
            $voisin = $voisin->previousSibling;
        }

        return $voisin instanceof DOMElement ? $voisin : throw new RuntimeException('Cet élément n\'a pas de voisin avant lui.');
    }

    /**
     * @return list<DOMElement>
     */
    private function enfants(DOMNode $parent, ?string $balise = null): array
    {
        $enfants = [];

        foreach ($parent->childNodes as $enfant) {
            if ($enfant instanceof DOMElement && ($balise === null || strtolower($enfant->nodeName) === $balise)) {
                $enfants[] = $enfant;
            }
        }

        return $enfants;
    }

    private function peutLire(string $nom): bool
    {
        $trouves = $this->chemin->query('.//*[@data-ecart="'.$nom.'"]', $this->carte);

        return $trouves !== false && $trouves->length > 0;
    }

    private function un(string $nom): DOMElement
    {
        return $this->premier('.//*[@data-ecart="'.$nom.'"]');
    }

    private function premier(string $requete): DOMElement
    {
        $trouves = $this->chemin->query($requete, $this->carte);
        $element = $trouves === false ? null : $trouves->item(0);

        return $element instanceof DOMElement ? $element : throw new RuntimeException('La carte n\'a pas d\'élément « '.$requete.' ».');
    }

    private function texteDe(DOMElement $element): string
    {
        return $this->texte($element);
    }

    /**
     * Le texte d'un élément, les espaces de la mise en page réduites à une
     * seule (les espaces insécables du texte restent).
     */
    private function texte(DOMNode $element): string
    {
        return trim((string) preg_replace('/[ \t\r\n]+/', ' ', (string) $element->textContent));
    }
}
