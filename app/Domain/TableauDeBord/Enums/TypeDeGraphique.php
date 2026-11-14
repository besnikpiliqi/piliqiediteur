<?php

namespace App\Domain\TableauDeBord\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * La forme d'une carte du tableau de bord, que la personne choisit dans le
 * menu de la carte parmi celles que son graphique permet (voir
 * Graphique::types()) : barres, lignes ou aires pour un graphique par mois,
 * anneau, camembert, barres ou barres couchées pour une répartition, et le
 * radar pour les ventes par jour de la semaine (décision de l'éditeur du
 * 14/11/2026, modèle « G1 + chiffres de G2 »). Chart.js, celui de Filament,
 * les dessine (voir typeChartJs()).
 */
enum TypeDeGraphique: string implements HasLabel
{
    case Barres = 'barres';
    case Lignes = 'lignes';
    case Aires = 'aires';
    case Anneau = 'anneau';
    case Camembert = 'camembert';
    case BarresCouchees = 'barres_couchees';
    case Radar = 'radar';

    public function getLabel(): string
    {
        return match ($this) {
            self::Barres => 'Barres',
            self::Lignes => 'Lignes',
            self::Aires => 'Aires',
            self::Anneau => 'Anneau',
            self::Camembert => 'Camembert',
            self::BarresCouchees => 'Barres couchées',
            self::Radar => 'Radar',
        };
    }

    /**
     * Le type de Chart.js qui le dessine : les aires sont des lignes
     * remplies, les barres couchées des barres sur l'axe vertical.
     */
    public function typeChartJs(): string
    {
        return match ($this) {
            self::Barres, self::BarresCouchees => 'bar',
            self::Lignes, self::Aires => 'line',
            self::Anneau => 'doughnut',
            self::Camembert => 'pie',
            self::Radar => 'radar',
        };
    }

    /**
     * Un cercle (anneau, camembert) : une couleur par part, sans axes.
     */
    public function estCirculaire(): bool
    {
        return $this === self::Anneau || $this === self::Camembert;
    }
}
