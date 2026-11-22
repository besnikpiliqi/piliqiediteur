<?php

namespace App\Domain\TableauDeBord\Support;

use App\Domain\TableauDeBord\Enums\NiveauDeLaBarre;
use Closure;

/**
 * Une série d'un graphique par période (voir ConstructeurDeSeriesParPeriode) :
 * son libellé, sa forme et ce qu'elle vaut sur tout intervalle de jours,
 * « AAAA-MM-JJ » du premier au dernier compris, quel que soit le niveau de
 * la barre qui le pose (un jour, un mois, un trimestre, une année, ou un
 * cumul depuis le début de l'année). Une série de flux (des ventes, une
 * TVA) additionne l'intervalle ; une valeur de stock ne regarde que son
 * dernier jour.
 *
 * La série principale d'une carte est celle que la comparaison avec
 * l'année précédente, l'écart en % sur un an et la tendance reprennent : la
 * seule si la carte n'en a qu'une, « Ventes HT » pour les ventes et les
 * achats, « TVA à payer » pour la TVA, « Nombre de ventes » pour le nombre
 * de ventes et le panier moyen (décision de Claude du 22/11/2026, D76).
 */
final readonly class DefinitionDeSerie
{
    /**
     * @param  Closure(string, string, NiveauDeLaBarre): int  $valeur  ce que la série vaut du premier au dernier jour, pour une barre de ce niveau
     * @param  'euros'|'nombre'  $unite
     */
    public function __construct(
        public string $libelle,
        public Closure $valeur,
        public bool $principale = false,
        public string $unite = SerieDuGraphique::EUROS,
        public bool $enLigne = false,
        public bool $axeDeDroite = false,
        public bool $pointilles = false,
    ) {}
}
