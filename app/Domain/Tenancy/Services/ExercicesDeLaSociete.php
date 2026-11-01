<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Models\ChangementFinExercice;
use App\Domain\Tenancy\Models\Societe;
use InvalidArgumentException;

/**
 * Les exercices d'une société, en jours de Paris : une même date de fin
 * pour tous ses magasins (voir CLAUDE.md, « Caisse et clôtures »). Un
 * exercice finit le dernier jour de son mois de fin d'exercice, sauf
 * quand le dernier changement légal de la date de clôture (voir
 * ChangementFinExercice) en a fixé un autre, par procès-verbal, à
 * l'exercice qui était en cours au jour de la procédure : celui-ci garde
 * le début qu'il avait et finit à la date du procès-verbal ; les
 * suivants finissent au nouveau mois, le premier pouvant être
 * transitoire. Les clôtures d'exercice de l'étape 3 suivront ces dates.
 *
 * Lecture de la société donnée et de ses changements, dans son contexte
 * (SocieteScope).
 */
class ExercicesDeLaSociete
{
    /**
     * Le premier jour de l'exercice en cours ce jour-là : le début gardé
     * par le dernier changement légal tant que la fin qu'il a fixée n'est
     * pas passée ; sinon le lendemain de la dernière fin passée, au mois de
     * fin d'exercice de la société ou à la date de ce changement.
     *
     * @throws InvalidArgumentException
     */
    public function debutDeLExerciceEnCours(Societe $societe, string $jour): string
    {
        $dernier = $this->dernierChangement($societe);

        if ($dernier !== null && $dernier->fin_exercice_en_cours_apres >= $jour) {
            return $dernier->debut_exercice_en_cours;
        }

        $finPassee = self::finDeMoisAvant((int) $societe->mois_fin_exercice, $jour);

        if ($dernier !== null && $dernier->fin_exercice_en_cours_apres > $finPassee) {
            $finPassee = $dernier->fin_exercice_en_cours_apres;
        }

        return Calendrier::ajouterJours($finPassee, 1);
    }

    /**
     * Le dernier jour de l'exercice en cours ce jour-là : celui que le
     * dernier changement légal a fixé, tant qu'il n'est pas passé ; sinon le
     * dernier jour du mois de fin d'exercice de la société qui tombe ce
     * jour-là ou après.
     *
     * @throws InvalidArgumentException
     */
    public function finDeLExerciceEnCours(Societe $societe, string $jour): string
    {
        $dernier = $this->dernierChangement($societe);

        if ($dernier !== null && $dernier->fin_exercice_en_cours_apres >= $jour) {
            return $dernier->fin_exercice_en_cours_apres;
        }

        return self::finDeMoisAuPlusTot((int) $societe->mois_fin_exercice, $jour);
    }

    /**
     * Le dernier jour de ce mois de l'année qui tombe ce jour-là ou après.
     *
     * @throws InvalidArgumentException
     */
    public static function finDeMoisAuPlusTot(int $mois, string $jour): string
    {
        $annee = (int) Calendrier::afficherJour($jour, 'Y');
        $fin = self::dernierJour($annee, $mois);

        return $fin >= $jour ? $fin : self::dernierJour($annee + 1, $mois);
    }

    /**
     * Le dernier jour de ce mois de l'année qui tombe avant ce jour-là.
     *
     * @throws InvalidArgumentException
     */
    public static function finDeMoisAvant(int $mois, string $jour): string
    {
        $annee = (int) Calendrier::afficherJour($jour, 'Y');
        $fin = self::dernierJour($annee, $mois);

        return $fin < $jour ? $fin : self::dernierJour($annee - 1, $mois);
    }

    /**
     * Ce jour est-il le dernier jour de son mois ?
     *
     * @throws InvalidArgumentException
     */
    public static function estUneFinDeMois(string $jour): bool
    {
        return Calendrier::premierJourDuMois(Calendrier::ajouterJours($jour, 1)) === Calendrier::ajouterJours($jour, 1);
    }

    /**
     * Le nombre de mois entiers ou entamés d'une période, du premier jour
     * au dernier, compris : un exercice du 1er janvier au 30 juin en
     * compte six.
     *
     * @throws InvalidArgumentException
     */
    public static function nombreDeMois(string $debut, string $fin): int
    {
        $lendemain = Calendrier::ajouterJours($fin, 1);
        $mois = ((int) Calendrier::afficherJour($lendemain, 'Y') - (int) Calendrier::afficherJour($debut, 'Y')) * 12
            + (int) Calendrier::afficherJour($lendemain, 'n') - (int) Calendrier::afficherJour($debut, 'n');

        return (int) Calendrier::afficherJour($lendemain, 'j') > (int) Calendrier::afficherJour($debut, 'j') ? $mois + 1 : $mois;
    }

    private function dernierChangement(Societe $societe): ?ChangementFinExercice
    {
        return ChangementFinExercice::query()->where('societe_id', $societe->getKey())->orderByDesc('id')->first();
    }

    private static function dernierJour(int $annee, int $mois): string
    {
        $premierDuMoisSuivant = sprintf('%04d-%02d-01', $mois === 12 ? $annee + 1 : $annee, $mois === 12 ? 1 : $mois + 1);

        return Calendrier::ajouterJours($premierDuMoisSuivant, -1);
    }
}
