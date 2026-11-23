<?php

namespace App\Domain\TableauDeBord\Support;

use App\Domain\TableauDeBord\Enums\NiveauDeLaBarre;
use Closure;

/**
 * Ce que la page écrit d'une carte par période (choix de l'éditeur du
 * 16/11/2026, lot GRAPH-AN ; maquette 01-graph-an.html du 22/11/2026) :
 * son dessin (voir SvgDesBarresParPeriode), le fil du détail ouvert (« Par
 * mois › 2025 › avril 2025 », avec « Revenir »), le menu « Afficher » et la
 * case « Ajouter la tendance » avec ce qui les grise, sa légende, la phrase
 * qui dit qu'une barre se clique, et le tableau « Voir les chiffres » des
 * mêmes valeurs au centime. Tout se lit sur la série et sur le calcul de la
 * page (voir CalculDesSeries), sans rien relire en base.
 *
 * @phpstan-type Lecture array{vide: bool, dessin: string|null, racine: string, chemin: list<array{jeton: string, libelle: string}>, enDetail: bool, ecart: bool, ecartPossible: bool, tendance: bool, tendancePossible: bool, tendanceLibelle: string, legende: list<array{libelle: string, forme: 'carre'|'trait'|'tirets', cle: string}>, cliquable: bool, astuce: string|null, tableau: array{tete: list<string>, lignes: list<array{libelle: string, cellules: list<array{texte: string, negatif: bool}>}>}}
 */
final class LectureDUneCarteParPeriode
{
    /**
     * @param  Closure(int): string  $euros  un montant en centimes, tel que l'écran l'écrit
     * @return Lecture
     */
    public static function lire(CarteDuTableauDeBord $carte, SerieDuGraphique $serie, CalculDesSeries $calcul, Closure $euros): array
    {
        $graphique = $carte->graphique;
        $exercices = $calcul->exercicesDecales();
        $niveau = $serie->barres[0]->niveau ?? $calcul->reglages->decoupage->niveau();
        $detail = $calcul->detailDe($graphique);
        $cumul = $calcul->cumulActif($carte);
        $cliquable = ! $cumul && ($niveau !== NiveauDeLaBarre::Jour || $graphique->listesDuJour($calcul->perimetre->societe->aDesArticles()) !== []);
        $vide = $serie->estVide();
        $titre = $serie->titre ?? $graphique->titre($niveau, $exercices);

        return [
            'vide' => $vide,
            'dessin' => $vide ? null : SvgDesBarresParPeriode::ecrire($serie, $carte->type, $graphique->value, $titre, $cliquable, $cumul, $euros),
            'racine' => ucfirst($calcul->reglages->decoupage->niveau()->parTerme($exercices)),
            'chemin' => array_map(fn (Barre $barre): array => ['jeton' => $barre->jeton(), 'libelle' => self::libelleDuFil($barre)], $calcul->chemin($graphique)),
            'enDetail' => $detail !== null,
            'ecart' => $calcul->ecartActif($carte),
            'ecartPossible' => $calcul->ecartPossible($carte),
            'tendance' => $carte->tendance,
            'tendancePossible' => $calcul->tendancePossible($carte),
            'tendanceLibelle' => 'Ajouter la tendance (moyenne sur 3 '.$niveau->pluriel($exercices).')',
            'legende' => SvgDesBarresParPeriode::legende($serie, $carte->type, $cumul),
            'cliquable' => $cliquable && ! $vide,
            'astuce' => $cliquable && ! $vide ? ($niveau === NiveauDeLaBarre::Jour ? 'Cliquer sur un jour pour ouvrir la liste de ses documents.' : 'Cliquer pour voir le détail : un clic sur une barre la découpe plus finement.') : null,
            'tableau' => self::tableau($serie, $niveau, $exercices, $euros),
        ];
    }

    /**
     * Le nom d'une barre dans le fil du détail : « 2025 », « T1 2026 » pour
     * une année ou un trimestre, « Avril 2025 » pour un mois.
     */
    private static function libelleDuFil(Barre $barre): string
    {
        return $barre->niveau === NiveauDeLaBarre::Mois ? $barre->intitule : $barre->etiquette;
    }

    /**
     * Le tableau « Voir les chiffres » : une ligne par barre, au nom de la
     * barre, une colonne par série ; en écart en %, l'écart puis les deux
     * montants qu'il compare. Un montant au centime, « – » sans valeur.
     *
     * @param  Closure(int): string  $euros
     * @return array{tete: list<string>, lignes: list<array{libelle: string, cellules: list<array{texte: string, negatif: bool}>}>}
     */
    private static function tableau(SerieDuGraphique $serie, NiveauDeLaBarre $niveau, bool $exercices, Closure $euros): array
    {
        $texte = function (?int $valeur, string $unite) use ($euros): array {
            if ($valeur === null) {
                return ['texte' => '–', 'negatif' => false];
            }

            return match ($unite) {
                SerieDuGraphique::NOMBRE => ['texte' => number_format($valeur, 0, ',', "\u{202F}"), 'negatif' => $valeur < 0],
                SerieDuGraphique::POURCENT => ['texte' => ($valeur > 0 ? '+' : '').CalculDesSeries::pourcentage($valeur), 'negatif' => $valeur < 0],
                default => ['texte' => $euros($valeur), 'negatif' => $valeur < 0],
            };
        };

        $lignes = [];

        if ($serie->ecartSurUnAn !== null) {
            $unite = $serie->ecartSurUnAn['unite'];
            $tete = ['', 'Écart sur un an', $niveau === NiveauDeLaBarre::Annee ? ($exercices ? 'Cet exercice' : 'Cette année') : 'Cette période', 'Un an plus tôt'];

            foreach ($serie->barres as $rang => $barre) {
                $ligne = $serie->ecartSurUnAn['lignes'][$rang] ?? ['actuel' => null, 'precedent' => null];
                $lignes[] = ['libelle' => $barre->intitule, 'cellules' => [$texte($serie->jeux[0]['valeurs'][$rang] ?? null, SerieDuGraphique::POURCENT), $texte($ligne['actuel'], $unite), $texte($ligne['precedent'], $unite)]];
            }

            return ['tete' => $tete, 'lignes' => $lignes];
        }

        foreach ($serie->barres as $rang => $barre) {
            $lignes[] = ['libelle' => $barre->intitule, 'cellules' => array_map(fn (array $jeu): array => $texte($jeu['valeurs'][$rang] ?? null, $jeu['unite']), $serie->jeux)];
        }

        return ['tete' => ['', ...array_map(fn (array $jeu): string => $jeu['libelle'], $serie->jeux)], 'lignes' => $lignes];
    }
}
