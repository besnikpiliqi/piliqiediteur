<?php

namespace App\Domain\TableauDeBord\Enums;

use App\Domain\TableauDeBord\Support\Mois;

/**
 * La période du tableau de bord (décision de l'éditeur du 14/11/2026) :
 * les 12 derniers mois (par défaut), l'année civile en cours ou la
 * précédente, l'exercice en cours ou le précédent, selon le mois de fin
 * d'exercice de la société. Ses mois se comptent depuis le jour de
 * l'écran : celui de Paris dans l'espace de la société, du cabinet et sur
 * la page de l'expert-comptable, celui du magasin dans son panneau.
 *
 * Quand l'exercice finit en décembre, l'exercice est l'année civile (choix
 * B de l'éditeur, le 15/11/2026) : le menu ne propose que les 12 derniers
 * mois, « Année 2026 (exercice en cours) » et « Année 2025 (exercice
 * précédent) » (voir duMenu() et libelle()), et une préférence
 * « exercice » enregistrée se lit comme l'année correspondante, la même
 * période (voir pourLaSociete()).
 */
enum PeriodeDuTableauDeBord: string
{
    case DouzeDerniersMois = 'douze_derniers_mois';
    case AnneeEnCours = 'annee_en_cours';
    case AnneePrecedente = 'annee_precedente';
    case ExerciceEnCours = 'exercice_en_cours';
    case ExercicePrecedent = 'exercice_precedent';

    /**
     * Le premier et le dernier mois de la période, compris, depuis ce jour
     * « AAAA-MM-JJ » et ce mois de fin d'exercice (1 à 12). L'exercice en
     * cours finit au mois de fin d'exercice qui vient (celui de ce jour
     * compris), et commence onze mois plus tôt.
     *
     * @return array{0: string, 1: string}
     */
    public function bornes(string $aujourdhui, int $moisDeFinDExercice): array
    {
        $ceMois = Mois::duJour($aujourdhui);
        $annee = Mois::annee($ceMois);
        $finDeLExercice = sprintf('%04d-%02d', Mois::numero($ceMois) <= $moisDeFinDExercice ? $annee : $annee + 1, $moisDeFinDExercice);

        return match ($this) {
            self::DouzeDerniersMois => [Mois::decaler($ceMois, -11), $ceMois],
            self::AnneeEnCours => [sprintf('%04d-01', $annee), sprintf('%04d-12', $annee)],
            self::AnneePrecedente => [sprintf('%04d-01', $annee - 1), sprintf('%04d-12', $annee - 1)],
            self::ExerciceEnCours => [Mois::decaler($finDeLExercice, -11), $finDeLExercice],
            self::ExercicePrecedent => [Mois::decaler($finDeLExercice, -23), Mois::decaler($finDeLExercice, -12)],
        };
    }

    /**
     * Les choix du menu « Période » d'une société dont l'exercice finit à
     * ce mois (1 à 12) : sans « Exercice » quand il finit en décembre,
     * l'exercice étant alors l'année civile.
     *
     * @return list<self>
     */
    public static function duMenu(int $moisDeFinDExercice): array
    {
        return $moisDeFinDExercice === 12
            ? [self::DouzeDerniersMois, self::AnneeEnCours, self::AnneePrecedente]
            : self::cases();
    }

    /**
     * La période telle que le menu de cette société la propose : l'exercice
     * en cours ou le précédent d'une société dont l'exercice finit en
     * décembre est l'année en cours ou la précédente, la même période.
     */
    public function pourLaSociete(int $moisDeFinDExercice): self
    {
        if ($moisDeFinDExercice !== 12) {
            return $this;
        }

        return match ($this) {
            self::ExerciceEnCours => self::AnneeEnCours,
            self::ExercicePrecedent => self::AnneePrecedente,
            default => $this,
        };
    }

    /**
     * Le libellé du menu : « Année 2026 », « Exercice en cours (juil. 2026
     * à juin 2027) » ; quand l'exercice finit en décembre, « Année 2026
     * (exercice en cours) » et « Année 2025 (exercice précédent) ».
     */
    public function libelle(string $aujourdhui, int $moisDeFinDExercice): string
    {
        [$premier, $dernier] = $this->bornes($aujourdhui, $moisDeFinDExercice);
        $exerciceCivil = $moisDeFinDExercice === 12;

        return match ($this) {
            self::DouzeDerniersMois => '12 derniers mois',
            self::AnneeEnCours => 'Année '.Mois::annee($premier).($exerciceCivil ? ' (exercice en cours)' : ''),
            self::AnneePrecedente => 'Année '.Mois::annee($premier).($exerciceCivil ? ' (exercice précédent)' : ''),
            self::ExerciceEnCours => 'Exercice en cours ('.Mois::libelle($premier).' à '.Mois::libelle($dernier).')',
            self::ExercicePrecedent => 'Exercice précédent ('.Mois::libelle($premier).' à '.Mois::libelle($dernier).')',
        };
    }

    /**
     * Un exercice : le chiffre d'affaires par an se compte alors par
     * exercice.
     */
    public function estUnExercice(): bool
    {
        return $this === self::ExerciceEnCours || $this === self::ExercicePrecedent;
    }
}
