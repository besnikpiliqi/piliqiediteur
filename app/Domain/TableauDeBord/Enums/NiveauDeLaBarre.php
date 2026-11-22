<?php

namespace App\Domain\TableauDeBord\Enums;

/**
 * Ce que couvre une barre d'un graphique par période (voir Barre) : un
 * jour, un mois, un trimestre ou une année (un exercice quand la société ne
 * clôt pas en décembre). Cliquer une barre la redécoupe plus finement :
 * une année ou un trimestre en mois, un mois en jours (décision de
 * l'éditeur du 16/11/2026, « Cliquer pour voir le détail »).
 */
enum NiveauDeLaBarre: string
{
    case Jour = 'jour';
    case Mois = 'mois';
    case Trimestre = 'trimestre';
    case Annee = 'annee';

    /**
     * Le niveau où une barre de ce niveau se redécoupe ; null pour un jour,
     * qui ouvre la liste de ses documents.
     */
    public function plusFin(): ?self
    {
        return match ($this) {
            self::Annee, self::Trimestre => self::Mois,
            self::Mois => self::Jour,
            self::Jour => null,
        };
    }

    /**
     * Le mot de la barre : « jour », « mois », « trimestre », « année », ou
     * « exercice » quand les années sont celles d'un exercice décalé.
     */
    public function terme(bool $exercices): string
    {
        return match ($this) {
            self::Jour => 'jour',
            self::Mois => 'mois',
            self::Trimestre => 'trimestre',
            self::Annee => $exercices ? 'exercice' : 'année',
        };
    }

    /**
     * Le pluriel de la barre, pour « Moyenne sur 3 mois » : « jours »,
     * « mois », « trimestres », « ans » ou « exercices ».
     */
    public function pluriel(bool $exercices): string
    {
        return match ($this) {
            self::Jour => 'jours',
            self::Mois => 'mois',
            self::Trimestre => 'trimestres',
            self::Annee => $exercices ? 'exercices' : 'ans',
        };
    }

    /**
     * Ce que dit « … par mois », « … par trimestre », « … par année » ou
     * « … par exercice » dans le titre d'une carte.
     */
    public function parTerme(bool $exercices): string
    {
        return 'par '.$this->terme($exercices);
    }

    /**
     * Le pas dit en toutes lettres dans la ligne grise d'une carte : « mois
     * par mois », « trimestre par trimestre », « année par année »,
     * « exercice par exercice », « jour par jour ».
     */
    public function pas(bool $exercices): string
    {
        $terme = $this->terme($exercices);

        return $terme.' par '.$terme;
    }
}
