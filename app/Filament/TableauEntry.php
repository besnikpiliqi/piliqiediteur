<?php

namespace App\Filament;

use Closure;
use Filament\Infolists\ComponentContainer;
use Filament\Infolists\Components\Component;
use Filament\Infolists\Components\Entry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Enums\Alignment;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Une liste d'une fiche montrée en un seul tableau, au modèle D choisi par
 * l'éditeur le 13/11/2026 (lot DEMO-V) : les titres une seule fois en
 * haut, une ligne par élément, dans l'ordre que donne la liste ; une
 * valeur chiffrée nulle (0, 0,00 €) s'écrit « – » en gris, et toute la
 * ligne passe en gris quand toutes ses valeurs chiffrées sont nulles ; le
 * total, quand la liste en a un, est sa dernière ligne, en gras, dans les
 * mêmes colonnes, et les lignes d'information viennent dessous, en gris ;
 * les nombres et les montants sont alignés à droite, en chiffres de même
 * largeur ; sur un téléphone, le tableau défile de côté dans son cadre
 * (voir resources/css/tableaux.css). Ce n'est que de l'affichage : aucun
 * montant enregistré ni aucun contenu signé ne change, et un export garde
 * ses valeurs chiffrées.
 *
 * Chaque colonne est une entrée du schéma, son libellé pour titre ; une
 * entrée alignée à droite (alignEnd()) est une colonne chiffrée, dont la
 * valeur nulle se juge sur ce que montre la cellule, jamais sur l'état
 * brut (un taux enregistré « 0.00 » s'écrit « 0 % », un taux et non une
 * valeur nulle). Une
 * entrée qui prend toute la largeur (columnSpanFull()), comme les lignes
 * d'un avoir sous l'avoir, s'affiche sous sa ligne, sur toute la largeur
 * du tableau. La ligne de total et les lignes d'information se donnent par
 * le nom de leurs colonnes, leurs valeurs déjà écrites (« 32,30 € ») ; une
 * ligne qui n'a de valeur que dans sa première colonne et ses dernières
 * étend son libellé sur les colonnes vides qui le suivent.
 */
class TableauEntry extends RepeatableEntry
{
    /**
     * @var view-string
     */
    protected string $view = 'filament.tableau-entry';

    /**
     * @var Closure|array<string, string|int|null>|null
     */
    protected Closure|array|null $ligneDeTotal = null;

    /**
     * @var Closure|list<array<string, string|int|null>>
     */
    protected Closure|array $lignesDInformation = [];

    /**
     * @param  Closure|array<string, string|int|null>|null  $ligne
     */
    public function ligneDeTotal(Closure|array|null $ligne): static
    {
        $this->ligneDeTotal = $ligne;

        return $this;
    }

    /**
     * @param  Closure|list<array<string, string|int|null>>  $lignes
     */
    public function lignesDInformation(Closure|array $lignes): static
    {
        $this->lignesDInformation = $lignes;

        return $this;
    }

    /**
     * Les colonnes du tableau, dans l'ordre du schéma : les entrées
     * visibles dans au moins une ligne (une entrée cachée pour toute la
     * fiche, comme le PU TTC d'un achat, n'en est pas une), ou toutes sur
     * le schéma vide quand la liste n'a que son total ; leur nom, leur
     * titre, lu sur la première ligne où elles se montrent, et si elles
     * sont chiffrées. Une entrée qui prend toute la largeur n'est pas une
     * colonne.
     *
     * @return array<int, array{nom: string, libelle: string, chiffre: bool}>
     */
    public function getColonnes(): array
    {
        $conteneurs = array_values($this->getChildComponentContainers());

        if ($conteneurs === []) {
            return array_map(fn (Component $composant): array => self::colonne($composant), $this->entreesDe($this->getChildComponentContainer()));
        }

        $colonnes = [];

        foreach ($conteneurs as $conteneur) {
            foreach ($this->entreesDe($conteneur) as $rang => $composant) {
                if (! isset($colonnes[$rang]) && ! $composant->isHidden()) {
                    $colonnes[$rang] = self::colonne($composant);
                }
            }
        }

        ksort($colonnes);

        return $colonnes;
    }

    /**
     * Les cellules d'une ligne, une par colonne du tableau : chaque entrée,
     * son libellé caché (le titre de sa colonne le dit), avec ce qu'il faut
     * pour la montrer au modèle D, une entrée cachée pour cette fiche
     * laissant sa cellule vide, une valeur nulle gardant l'aide qui
     * l'explique ; puis les entrées qui prennent toute la largeur, à
     * montrer sous la ligne.
     *
     * @return array{cellules: list<array{composant: Component, chiffre: bool, nulle: bool, cachee: bool, aide: string|Htmlable|null}>, dessous: list<Component>, nulle: bool}
     */
    public function getLigne(ComponentContainer $conteneur): array
    {
        $entrees = $this->entreesDe($conteneur);
        $cellules = [];

        foreach (array_keys($this->getColonnes()) as $rang) {
            $composant = $entrees[$rang];

            if ($composant instanceof Entry) {
                $composant->hiddenLabel();
            }

            $cachee = $composant->isHidden();
            $cellules[] = [
                'composant' => $composant,
                'chiffre' => self::estChiffre($composant),
                'nulle' => ! $cachee && self::estChiffre($composant) && self::estNulle(self::valeurAffichee($composant)),
                'cachee' => $cachee,
                'aide' => $composant instanceof Entry ? $composant->getHelperText() : null,
            ];
        }

        $chiffrees = array_filter($cellules, fn (array $cellule): bool => $cellule['chiffre'] && ! $cellule['cachee']);

        return [
            'cellules' => $cellules,
            'dessous' => array_values(array_filter($conteneur->getComponents(), fn (Component $composant): bool => self::prendTouteLaLargeur($composant))),
            'nulle' => $chiffrees !== [] && array_filter($chiffrees, fn (array $cellule): bool => ! $cellule['nulle'] && ! self::estVide($cellule['composant'])) === [],
        ];
    }

    /**
     * La ligne de total, null quand la liste n'en a pas.
     *
     * @return list<array{valeur: string, colspan: int, chiffre: bool, nulle: bool}>|null
     */
    public function getTotal(): ?array
    {
        $ligne = $this->evaluate($this->ligneDeTotal);

        return is_array($ligne) ? $this->cellulesEcrites(self::valeursEcrites($ligne)) : null;
    }

    /**
     * Les lignes d'information, sous le total.
     *
     * @return list<list<array{valeur: string, colspan: int, chiffre: bool, nulle: bool}>>
     */
    public function getInformations(): array
    {
        $lignes = $this->evaluate($this->lignesDInformation);

        return is_array($lignes) ? array_values(array_map(fn (mixed $ligne): array => $this->cellulesEcrites(self::valeursEcrites($ligne)), $lignes)) : [];
    }

    /**
     * Les valeurs déjà écrites d'une ligne de total ou d'information, par
     * le nom de leur colonne ; ce qui n'en est pas une reste hors du
     * tableau.
     *
     * @return array<string, string|int|null>
     */
    private static function valeursEcrites(mixed $ligne): array
    {
        $valeurs = [];

        foreach (is_array($ligne) ? $ligne : [] as $nom => $valeur) {
            if (is_string($nom) && (is_string($valeur) || is_int($valeur) || $valeur === null)) {
                $valeurs[$nom] = $valeur;
            }
        }

        return $valeurs;
    }

    /**
     * Une valeur chiffrée nulle : 0, « 0 », « 0.00 », « 0,00 € »,
     * « -0,00 € ». Une valeur absente n'est pas nulle : elle reste vide ;
     * un taux de 0 % non plus : c'est un taux, pas une valeur nulle.
     */
    public static function estNulle(mixed $valeur): bool
    {
        if ($valeur instanceof Htmlable) {
            $valeur = strip_tags($valeur->toHtml());
        }

        if (is_int($valeur) || is_float($valeur)) {
            return $valeur == 0;
        }

        if (! is_string($valeur)) {
            return false;
        }

        return preg_match('/^[-−]?0+([.,]0+)?\s*€?$/u', trim(str_replace(["\u{00A0}", "\u{202F}"], ' ', $valeur))) === 1;
    }

    /**
     * La valeur que montre la cellule d'une entrée : son état tel que son
     * format l'écrit (« 0 % » pour un taux enregistré « 0.00 », « 0,00 € »
     * pour un montant), pour qu'un taux de 0 % reste un taux et ne
     * devienne jamais « – » ; une valeur absente, une liste ou un objet
     * restent tels quels.
     */
    private static function valeurAffichee(Component $composant): mixed
    {
        if (! $composant instanceof Entry) {
            return null;
        }

        $valeur = $composant->getState();

        if (! $composant instanceof TextEntry || ! is_scalar($valeur) || $valeur === '') {
            return $valeur;
        }

        return $composant->formatState($valeur);
    }

    /**
     * Les entrées d'une ligne qui peuvent être des colonnes, cachées
     * comprises, par rang : toutes, sauf celles qui prennent toute la
     * largeur.
     *
     * @return list<Component>
     */
    private function entreesDe(ComponentContainer $conteneur): array
    {
        return array_values(array_filter($conteneur->getComponents(withHidden: true), fn (Component $composant): bool => ! self::prendTouteLaLargeur($composant)));
    }

    /**
     * @return array{nom: string, libelle: string, chiffre: bool}
     */
    private static function colonne(Component $composant): array
    {
        return [
            'nom' => $composant instanceof Entry ? $composant->getName() : '',
            'libelle' => $composant instanceof Entry ? self::libelle($composant) : '',
            'chiffre' => self::estChiffre($composant),
        ];
    }

    private static function prendTouteLaLargeur(Component $composant): bool
    {
        return $composant->getColumnSpan('default') === 'full';
    }

    /**
     * Une ligne donnée par le nom de ses colonnes, mise en cellules : une
     * colonne absente reste vide ; les colonnes vides qui suivent la
     * première s'y joignent, pour qu'un long libellé tienne sur une ligne.
     *
     * @param  array<string, string|int|null>  $ligne
     * @return list<array{valeur: string, colspan: int, chiffre: bool, nulle: bool}>
     */
    private function cellulesEcrites(array $ligne): array
    {
        $cellules = [];

        $colonnes = array_values($this->getColonnes());

        foreach ($colonnes as $rang => $colonne) {
            $valeur = $ligne[$colonne['nom']] ?? null;

            if ($rang > 0 && $valeur === null && count($cellules) === 1 && self::uneValeurSuit($ligne, $colonnes, $rang)) {
                $cellules[0]['colspan']++;

                continue;
            }

            $cellules[] = [
                'valeur' => $valeur === null ? '' : (string) $valeur,
                'colspan' => 1,
                'chiffre' => $colonne['chiffre'],
                'nulle' => $colonne['chiffre'] && self::estNulle($valeur),
            ];
        }

        return $cellules;
    }

    /**
     * Une colonne de ce rang ou d'après a-t-elle une valeur ? Sinon, la
     * fin de la ligne reste en cellules vides.
     *
     * @param  array<string, string|int|null>  $ligne
     * @param  list<array{nom: string, libelle: string, chiffre: bool}>  $colonnes
     */
    private static function uneValeurSuit(array $ligne, array $colonnes, int $rang): bool
    {
        foreach (array_slice($colonnes, $rang) as $colonne) {
            if (($ligne[$colonne['nom']] ?? null) !== null) {
                return true;
            }
        }

        return false;
    }

    private static function libelle(Entry $entree): string
    {
        $libelle = $entree->getLabel();

        return $libelle instanceof Htmlable ? strip_tags($libelle->toHtml()) : (string) $libelle;
    }

    private static function estChiffre(Component $composant): bool
    {
        if (! $composant instanceof Entry) {
            return false;
        }

        $alignement = $composant->getAlignment();

        return $alignement === Alignment::End || $alignement === Alignment::Right || $alignement === 'end' || $alignement === 'right';
    }

    private static function estVide(Component $composant): bool
    {
        if (! $composant instanceof Entry) {
            return true;
        }

        $valeur = $composant->getState();

        return $valeur === null || $valeur === '' || $valeur === [];
    }
}
