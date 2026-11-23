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
 *  - par mois, la page écrit les douze lectures du mois, chacune dans un
 *    bloc `data-ecart-mois` (le titre du mois, le grand chiffre, la
 *    pastille et le détail ; le calcul achat par achat) que la donnée Alpine
 *    `mois` de la carte montre ou cache sans appeler le serveur (décision
 *    de l'éditeur du 23/11/2026) : la lecture ne garde que ceux que la page
 *    montre, ni `x-cloak` ni `display: none` écrit, ou, quand un mois est
 *    donné à lire(), ceux de ce mois ; elle refuse une page qui en montre
 *    plusieurs à la fois ;
 *  - la page écrit les deux affichages (décision de l'éditeur du 23/11/2026 :
 *    le menu « Afficher : » ne rappelle pas le serveur), chacun dans un bloc
 *    `data-ecart-affichage` que la donnée Alpine `affichage` de la carte
 *    montre ou cache : la lecture ne garde que celui que la page montre,
 *    ni `x-cloak` ni `display: none` écrit, ou celui qu'on lui demande ; par
 *    mois, ni les cartes de chiffres des douze mois (`stats` vaut null) ;
 *    sur douze mois, ni le dessin ni ses légendes (`dessin` et `legendes`
 *    valent null), ni le tableau « Voir les chiffres » ;
 *  - les lignes du calcul achat par achat ne sont pas écrites en HTML : la
 *    page les porte en JSON compact (le `script` de la vue de la carte, qui
 *    écrit chaque classe une seule fois, avec son préfixe, dans sa table
 *    `classes`, chaque ligne et chaque cellule en donnant le rang) et le
 *    navigateur les pose dans le tableau quand le calcul est ouvert ; la
 *    lecture prend les lignes du tableau quand il en a, sinon celles du JSON,
 *    qui sont les mêmes ; une ligne ou une cellule posée dont une classe n'a
 *    pas le préfixe est refusée, puisque la feuille ne la connaîtrait pas ;
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
     * La carte de ce graphique dans cette page, avec les blocs du mois que
     * la page montre, ou ceux de ce mois s'il est donné (la page écrite par
     * le serveur ne montre que le mois de départ ; ce qu'elle écrit pour les
     * autres mois se lit ainsi).
     *
     * @param  'avant'|'debut'|null  $affichage  celui à lire ; null : celui que la page montre
     * @return array<string, mixed>
     */
    public static function lire(string $html, string $graphique = 'ecart_des_prix_d_achat', ?int $mois = null, ?string $affichage = null): array
    {
        $document = (new HTML5(['disable_html_ns' => true]))->loadHTML('<!DOCTYPE html><html><body>'.$html.'</body></html>');
        $chemin = new DOMXPath($document);
        $cartes = $chemin->query('//*[@data-graphique="'.$graphique.'"]');

        if ($cartes === false || $cartes->length !== 1 || ! $cartes->item(0) instanceof DOMElement) {
            throw new RuntimeException('La page ne porte pas exactement une carte « '.$graphique.' ».');
        }

        $lecteur = new self($document, $chemin, $cartes->item(0));
        $lecteur->garderLAffichage($affichage);
        $lecteur->garderLesBlocsDuMois($mois);

        return $lecteur->lecture();
    }

    /**
     * Le premier écart entre ce que la page écrit pour un mois de l'affichage
     * par mois (le titre du mois, le grand chiffre, la pastille, le détail
     * et le calcul achat par achat) et le relevé de la maquette pour ce mois ;
     * null quand c'est la maquette. Le dessin, que le mois choisi change dans
     * le navigateur, n'en fait pas partie.
     *
     * @param  array<string, mixed>  $lue  ce que lire() a rendu, pour ce mois
     */
    public static function premierEcartDuMois(array $lue, int $mois): ?string
    {
        $attendu = LectureDesFixtures::verite()['etats']['avant'][$mois];
        $extrait = fn (array $lecture): array => [
            'moisChoisi' => $lecture['moisChoisi'],
            'grand' => $lecture['grand'],
            'pastille' => $lecture['pastille'],
            'detail' => $lecture['detail'],
            'calcul' => array_diff_key($lecture['calcul'], ['lien' => true]),
        ];

        return LectureDesFixtures::premierEcart($extrait($lue), $extrait($attendu));
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
     * Retire le bloc `data-ecart-affichage` qui n'est pas celui de
     * l'affichage lu : celui qu'on demande, sinon celui que la page montre
     * (ni `x-cloak`, que la page n'a pas encore levé, ni `display: none`,
     * que la donnée Alpine `affichage` écrit).
     */
    private function garderLAffichage(?string $affichage): void
    {
        $blocs = $this->chemin->query('.//*[@data-ecart-affichage]', $this->carte);

        foreach ($blocs === false ? [] : iterator_to_array($blocs) as $bloc) {
            if (! $bloc instanceof DOMElement) {
                continue;
            }

            $garde = $affichage === null
                ? ! $bloc->hasAttribute('x-cloak') && preg_match('/display:\s*none/i', $bloc->getAttribute('style')) !== 1
                : $bloc->getAttribute('data-ecart-affichage') === $affichage;

            if (! $garde) {
                $bloc->parentNode?->removeChild($bloc);
            }
        }

        $restants = $this->chemin->query('.//*[@data-ecart-affichage]', $this->carte);

        if ($restants !== false && $restants->length > 1) {
            throw new RuntimeException('La carte montre '.$restants->length.' affichages à la fois.');
        }
    }

    /**
     * Retire les blocs `data-ecart-mois` qui ne sont pas ceux du mois lu :
     * ceux de ce mois s'il est donné, sinon ceux que la page montre (ni
     * `x-cloak`, que la page n'a pas encore levé, ni `display: none`, que la
     * donnée Alpine `mois` écrit). Il en reste deux par mois (la lecture et
     * le calcul), aucun sur douze mois.
     */
    private function garderLesBlocsDuMois(?int $mois): void
    {
        $blocs = $this->chemin->query('.//*[@data-ecart-mois]', $this->carte);

        foreach ($blocs === false ? [] : iterator_to_array($blocs) as $bloc) {
            if (! $bloc instanceof DOMElement) {
                continue;
            }

            $garde = $mois === null
                ? ! $bloc->hasAttribute('x-cloak') && preg_match('/display:\s*none/i', $bloc->getAttribute('style')) !== 1
                : (int) $bloc->getAttribute('data-ecart-mois') === $mois;

            if (! $garde) {
                $bloc->parentNode?->removeChild($bloc);
            }
        }

        $restants = $this->chemin->query('.//*[@data-ecart-mois]', $this->carte);

        if ($restants !== false && ! in_array($restants->length, [0, 2], true)) {
            throw new RuntimeException('La carte montre '.$restants->length.' blocs de mois à la fois : la lecture d\'un mois et son calcul, deux au plus.');
        }
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
                'lignes' => $this->lignesDuCalcul($this->un('calcul-corps')),
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
     * Les lignes du tableau du calcul : celles que le navigateur y a posées,
     * sinon celles que la page porte en JSON pour ce mois (ou pour les douze
     * mois), qui sont les mêmes.
     *
     * @return list<array{classe: string, cellules: list<array{t: string, c: string, colspan: int}>}>
     */
    private function lignesDuCalcul(DOMElement $corps): array
    {
        $posees = $this->lignes($corps);

        if ($posees !== []) {
            return $posees;
        }

        $mois = null;
        $bloc = $corps->parentNode;

        while ($bloc !== null && ! ($bloc instanceof DOMElement && ($bloc->hasAttribute('data-ecart-mois') || $bloc->hasAttribute('data-ecart-affichage')))) {
            $bloc = $bloc->parentNode;
        }

        if ($bloc instanceof DOMElement && $bloc->hasAttribute('data-ecart-mois')) {
            $mois = (int) $bloc->getAttribute('data-ecart-mois');
        }

        $script = $this->premier('.//script[@data-ecart="calculs"]');
        $calculs = json_decode($script->textContent, true, 512, JSON_THROW_ON_ERROR);
        $rangees = is_array($calculs) ? ($mois === null ? ($calculs['debut'][0] ?? null) : ($calculs['avant'][$mois] ?? null)) : null;

        if (! is_array($rangees)) {
            throw new RuntimeException('La carte ne porte pas les lignes de ce calcul en JSON.');
        }

        $classes = $calculs['classes'] ?? null;

        if (! is_array($classes)) {
            throw new RuntimeException('La carte ne porte pas la table des classes de ses lignes en JSON.');
        }

        return array_map(fn (array $ligne): array => [
            'classe' => $this->sansPrefixe((string) $classes[(int) $ligne[0]]),
            'cellules' => array_map(function (array|string $cellule) use ($classes): array {
                [$texte, $rang, $etendue] = is_string($cellule) ? [$cellule, 0, 1] : [$cellule[0], $cellule[1] ?? 0, $cellule[2] ?? 1];

                return ['t' => (string) $texte, 'c' => $this->sansPrefixe((string) $classes[(int) $rang]), 'colspan' => (int) $etendue];
            }, $ligne[1]),
        ], array_values($rangees));
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
                $cellules[] = ['t' => (string) $cellule->textContent, 'c' => $this->classesPrefixees($cellule), 'colspan' => $cellule->hasAttribute('colspan') ? (int) $cellule->getAttribute('colspan') : 1];
            }

            $lignes[] = ['classe' => $this->classesPrefixees($ligne), 'cellules' => $cellules];
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
        return $this->sansPrefixe($element->getAttribute('class'));
    }

    /**
     * Les classes d'une ligne ou d'une cellule d'un tableau, sans le préfixe
     * de la feuille des graphiques ; une classe qui ne l'a pas est refusée.
     */
    private function classesPrefixees(DOMElement $element): string
    {
        foreach (preg_split('/\s+/', trim($element->getAttribute('class')), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $classe) {
            if (! str_starts_with($classe, self::PREFIXE_DES_CLASSES)) {
                throw new RuntimeException('La classe « '.$classe.' » d\'une ligne ou d\'une cellule du tableau n\'a pas le préfixe « '.self::PREFIXE_DES_CLASSES.' » : la feuille des graphiques ne la connaît pas.');
            }
        }

        return $this->classes($element);
    }

    /**
     * Ces classes, sans le préfixe de la feuille des graphiques.
     */
    private function sansPrefixe(string $classes): string
    {
        $noms = preg_split('/\s+/', trim($classes), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return implode(' ', array_map(fn (string $classe): string => str_starts_with($classe, self::PREFIXE_DES_CLASSES) ? substr($classe, strlen(self::PREFIXE_DES_CLASSES)) : $classe, $noms));
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
