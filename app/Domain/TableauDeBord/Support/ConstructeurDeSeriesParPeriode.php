<?php

namespace App\Domain\TableauDeBord\Support;

use App\Domain\TableauDeBord\Enums\DecoupageDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\NiveauDeLaBarre;
use App\Domain\TableauDeBord\Enums\PeriodeDuTableauDeBord;
use Closure;

/**
 * La série d'une carte par période pour ses barres (voir CalculDesSeries
 * pour la façon dont les réglages se composent) : chaque série vaut, pour
 * chaque barre, ce que sa définition donne du premier au dernier jour de la
 * barre, ou du début de l'année (de l'exercice) en cours à son dernier jour
 * pour le cumul ; une barre à venir n'a pas de valeur (null).
 *
 * - La comparaison ajoute la série principale d'un an plus tôt, barre par
 *   barre : une barre en cours se compare aux mêmes jours de l'an dernier,
 *   et le cumul à l'année précédente cumulée de la même façon.
 * - L'écart en % sur un an remplace les montants par un pourcentage par
 *   barre, sur la valeur absolue du même montant un an plus tôt, « – »
 *   (null) quand il valait 0 ; l'info-bulle et le tableau gardent les deux
 *   montants (voir SerieDuGraphique::$ecartSurUnAn).
 * - La tendance est, pour chaque barre, la moyenne de sa valeur et des deux
 *   précédentes, arrondie au centime, sans point pour les deux premières.
 *   Elle se lit sur les valeurs que la carte montre, jamais sur un montant
 *   affiché.
 * - Par année, les années du début sans aucune donnée ne sont pas
 *   affichées, une année vide au milieu reste à 0.
 */
final class ConstructeurDeSeriesParPeriode
{
    /**
     * @param  list<DefinitionDeSerie>  $definitions  celles de la carte, dont au plus une principale (la première sinon)
     * @param  (Closure(string, string): ?string)|null  $infoBulle  la précision d'une barre, du premier au dernier jour qu'elle compte (le taux de marge)
     * @param  string|null  $noteDuJour  ce que dit la carte d'un détail par jour, quand sa série n'y compte pas tout
     * @param  string|null  $noteDeLaCarte  ce que la carte dit toujours, avant ses autres phrases (la TVA sans les charges)
     */
    public static function construire(CalculDesSeries $calcul, CarteDuTableauDeBord $carte, array $definitions, ?Closure $infoBulle = null, ?string $noteDuJour = null, ?string $noteDeLaCarte = null): SerieDuGraphique
    {
        $barres = $calcul->barresDe($carte);
        $detail = $calcul->detailDe($carte->graphique);
        $cumul = $calcul->cumulActif($carte);
        $aujourdhui = $calcul->aujourdhui();
        $exercices = $calcul->exercicesDecales();
        $debut = $cumul ? $barres[0]->premierJour : null;
        $principal = self::rangDeLaPrincipale($definitions);
        $valeurs = [];

        foreach ($definitions as $definition) {
            $valeurs[] = array_map(fn (Barre $barre): ?int => $barre->aVenir ? null : ($definition->valeur)($debut ?? $barre->premierJour, $barre->dernierJour, $barre->niveau), $barres);
        }

        $cachees = $detail === null && $barres[0]->niveau === NiveauDeLaBarre::Annee ? self::anneesSansDonnee($valeurs) : 0;
        $barres = array_slice($barres, $cachees);
        $valeurs = array_map(fn (array $serie): array => array_slice($serie, $cachees), $valeurs);
        $niveau = $barres[0]->niveau;
        $notes = array_values(array_filter([$noteDeLaCarte, $cachees > 0 ? TextesDesPeriodes::anneesCachees($cachees, $exercices) : null]));
        $unite = $definitions[$principal]->unite;
        $ecartSurUnAn = null;
        $jeux = [];

        if ($calcul->ecartActif($carte)) {
            $precedents = self::valeursUnAnPlusTot($definitions[$principal], $barres, null, $aujourdhui);
            $pourcentages = [];
            $lignes = [];

            foreach ($barres as $rang => $barre) {
                $actuel = $valeurs[$principal][$rang];
                $precedent = $precedents[$rang];
                $pourcentages[] = $actuel === null || $precedent === null ? null : CalculDesSeries::ecartSurUnAn($actuel, $precedent);
                $lignes[] = ['actuel' => $actuel, 'precedent' => $precedent];
            }

            $jeux[] = SerieDuGraphique::jeu('Écart sur un an', $pourcentages, SerieDuGraphique::POURCENT, role: SerieDuGraphique::ROLE_ECART);
            $ecartSurUnAn = ['unite' => $unite, 'lignes' => $lignes];
            $notes[] = TextesDesPeriodes::ecart(self::uneBarreEstEnCours($barres));

            if ($carte->tendance || $calcul->reglages->comparer || ($calcul->reglages->cumul && $calcul->reglages->cumulPossible() && $carte->graphique->accepteLeCumul())) {
                $notes[] = TextesDesPeriodes::ECART_SEUL;
            }
        } else {
            foreach ($definitions as $rang => $definition) {
                $jeux[] = SerieDuGraphique::jeu($definition->libelle, $valeurs[$rang], $definition->unite, $definition->enLigne || $cumul, $definition->axeDeDroite, $definition->pointilles);
            }

            if ($calcul->comparaisonActive($carte)) {
                $principale = $definitions[$principal];
                $debutPrecedent = $cumul ? Mois::premierJour(Mois::decaler(Mois::duJour($barres[0]->premierJour), -12)) : null;
                $jeux[] = SerieDuGraphique::jeu($principale->libelle.' (un an plus tôt)', self::valeursUnAnPlusTot($principale, $barres, $debutPrecedent, $aujourdhui), $unite, $principale->enLigne || $cumul, $principale->axeDeDroite, $principale->pointilles, SerieDuGraphique::ROLE_COMPARAISON);

                if (self::uneBarreEstEnCours($barres)) {
                    $notes[] = TextesDesPeriodes::MEMES_JOURS;
                }
            }

            if ($calcul->tendanceActive($carte)) {
                $jeux[] = SerieDuGraphique::jeu('Moyenne sur 3 '.$niveau->pluriel($exercices), self::moyennesSurTrois($valeurs[$principal]), $unite, true, $definitions[$principal]->axeDeDroite, role: SerieDuGraphique::ROLE_TENDANCE);
            }
        }

        if ($cumul) {
            $notes[] = TextesDesPeriodes::cumul($exercices, $calcul->reglages->periode === PeriodeDuTableauDeBord::DouzeDerniersMois);

            if ($carte->tendance) {
                $notes[] = TextesDesPeriodes::TENDANCE_ET_CUMUL;
            }
        }

        if ($detail === null && $calcul->reglages->cumul && $calcul->reglages->decoupage !== DecoupageDuTableauDeBord::Annee && ! $carte->graphique->accepteLeCumul()) {
            $notes[] = TextesDesPeriodes::CUMUL_D_UN_STOCK;
        }

        if ($detail !== null) {
            $notes[] = TextesDesPeriodes::detail($detail->intitule);
        }

        if ($niveau === NiveauDeLaBarre::Jour && $noteDuJour !== null) {
            $notes[] = $noteDuJour;
        }

        return new SerieDuGraphique(
            array_map(fn (Barre $barre): string => $barre->etiquette, $barres),
            $jeux,
            $notes === [] ? null : implode(' ', $notes),
            $infoBulle === null ? [] : self::infoBulles($barres, $debut, $infoBulle),
            null,
            $barres,
            $carte->graphique->titre($niveau, $exercices),
            $carte->graphique->ceQuIlCompte($niveau, $exercices),
            $ecartSurUnAn,
        );
    }

    /**
     * Le rang de la série principale : la première marquée comme telle, la
     * première de la carte sinon.
     *
     * @param  list<DefinitionDeSerie>  $definitions
     */
    private static function rangDeLaPrincipale(array $definitions): int
    {
        foreach ($definitions as $rang => $definition) {
            if ($definition->principale) {
                return $rang;
            }
        }

        return 0;
    }

    /**
     * Le nombre d'années du début dont aucune série n'a de valeur non
     * nulle ; aucune quand toutes en sont là, la carte est alors vide et le
     * dit.
     *
     * @param  list<list<int|null>>  $valeurs  par série, une valeur par barre
     */
    private static function anneesSansDonnee(array $valeurs): int
    {
        $barres = count($valeurs[0]);
        $vides = 0;

        while ($vides < $barres && array_filter(array_column($valeurs, $vides), fn (?int $valeur): bool => $valeur !== null && $valeur !== 0) === []) {
            $vides++;
        }

        return $vides === $barres ? 0 : $vides;
    }

    /**
     * Ce que valait cette série un an plus tôt, barre par barre ; null pour
     * une barre à venir. Avec un début, c'est le cumul depuis ce début.
     *
     * @param  list<Barre>  $barres
     * @return list<int|null>
     */
    private static function valeursUnAnPlusTot(DefinitionDeSerie $definition, array $barres, ?string $debut, string $aujourdhui): array
    {
        $valeurs = [];

        foreach ($barres as $barre) {
            [$du, $au] = $barre->unAnPlusTot($aujourdhui);
            $valeurs[] = $barre->aVenir ? null : ($definition->valeur)($debut ?? $du, $au, $barre->niveau);
        }

        return $valeurs;
    }

    /**
     * La moyenne de chaque valeur et des deux précédentes, arrondie au
     * centime ; rien pour les deux premières, ni pour une barre dont l'une
     * des trois est à venir.
     *
     * @param  list<int|null>  $valeurs
     * @return list<int|null>
     */
    private static function moyennesSurTrois(array $valeurs): array
    {
        $moyennes = [];

        foreach ($valeurs as $rang => $valeur) {
            $trois = $rang < 2 ? [null] : [$valeur, $valeurs[$rang - 1], $valeurs[$rang - 2]];
            $moyennes[] = in_array(null, $trois, true) ? null : CalculDesSeries::diviserArrondi(array_sum($trois), 3);
        }

        return $moyennes;
    }

    /**
     * @param  list<Barre>  $barres
     */
    private static function uneBarreEstEnCours(array $barres): bool
    {
        foreach ($barres as $barre) {
            if ($barre->enCours) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Barre>  $barres
     * @param  Closure(string, string): ?string  $infoBulle
     * @return array<int, string>
     */
    private static function infoBulles(array $barres, ?string $debut, Closure $infoBulle): array
    {
        $infoBulles = [];

        foreach ($barres as $rang => $barre) {
            $texte = $barre->aVenir ? null : $infoBulle($debut ?? $barre->premierJour, $barre->dernierJour);

            if ($texte !== null) {
                $infoBulles[$rang] = $texte;
            }
        }

        return $infoBulles;
    }
}
