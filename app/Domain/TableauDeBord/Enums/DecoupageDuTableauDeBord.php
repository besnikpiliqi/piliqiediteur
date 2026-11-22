<?php

namespace App\Domain\TableauDeBord\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Le menu « Découper par » du tableau de bord (choix de l'éditeur du
 * 16/11/2026, « Par trimestre », « Par année, sur 10 ans » ; semaine, jour
 * et « Du … au … » n'ont pas été retenus) : les graphiques par période
 * montrent une barre par mois (le défaut), par trimestre ou par année (un
 * exercice quand la société ne clôt pas en décembre). Le choix vaut pour
 * tout l'écran et se retient par compte, avec la période et les filtres
 * (voir ReglagesDuTableauDeBord).
 *
 * Par année, la période n'a plus de sens : l'écran montre les dix dernières
 * années (voir DecoupageDesPeriodes::ANNEES), jusqu'à l'année ou l'exercice
 * en cours, et les anneaux et classements comptent ces dix ans (décision de
 * Claude du 22/11/2026, D76).
 */
enum DecoupageDuTableauDeBord: string implements HasLabel
{
    case Mois = 'mois';
    case Trimestre = 'trimestre';
    case Annee = 'annee';

    public function getLabel(): string
    {
        return match ($this) {
            self::Mois => 'Mois',
            self::Trimestre => 'Trimestre',
            self::Annee => 'Année',
        };
    }

    /**
     * Le niveau des barres de ce découpage.
     */
    public function niveau(): NiveauDeLaBarre
    {
        return match ($this) {
            self::Mois => NiveauDeLaBarre::Mois,
            self::Trimestre => NiveauDeLaBarre::Trimestre,
            self::Annee => NiveauDeLaBarre::Annee,
        };
    }
}
