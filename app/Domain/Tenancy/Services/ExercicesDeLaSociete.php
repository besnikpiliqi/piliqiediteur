<?php

namespace App\Domain\Tenancy\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Tenancy\Models\ChangementFinExercice;
use App\Domain\Tenancy\Models\Societe;
use App\Domain\Tenancy\Scopes\SocieteScope;
use App\Domain\Tenancy\Support\ValeurRequise;
use InvalidArgumentException;

/**
 * Les exercices d'une société, en jours de Paris : une même date de fin
 * pour tous ses magasins (voir CLAUDE.md, « Caisse et clôtures »). Un
 * exercice finit le dernier jour de son mois de fin d'exercice ; chaque
 * changement légal de la date de clôture (voir ChangementFinExercice)
 * fixe, par procès-verbal, la fin de l'exercice qui était en cours au jour
 * de la procédure, qui garde son début, et le mois des exercices qui le
 * suivent, le premier pouvant être transitoire.
 *
 * L'exercice de n'importe quel jour, passé ou à venir, se reconstitue par
 * l'historique des changements, dans leur ordre : celui d'un jour antérieur
 * à l'exercice du premier changement suit le mois que ce changement a
 * quitté ; celui d'un jour qui suit l'exercice fixé par un changement, le
 * mois de ce changement, celui que quitte le changement suivant ou, après
 * le dernier, le mois de la société. Les clôtures d'exercice et leurs
 * rattrapages de l'étape 3 suivront ces dates.
 *
 * Lecture de la société donnée et de ses changements, filtrés
 * explicitement par elle, quelle que soit la société active : l'espace d'un
 * cabinet lit ainsi les exercices de chacun de ses clients liés (réglages
 * fiscaux communs, voir la puce « Produit » de CLAUDE.md), sans que la
 * portée de la société du cabinet (SocieteScope) ne cache leurs
 * changements.
 */
class ExercicesDeLaSociete
{
    /**
     * Le premier jour de l'exercice qui comprend ce jour-là.
     *
     * @throws InvalidArgumentException
     */
    public function debutDeLExerciceEnCours(Societe $societe, string $jour): string
    {
        return $this->exerciceDuJour($societe, $jour)[0];
    }

    /**
     * Le dernier jour de l'exercice qui comprend ce jour-là.
     *
     * @throws InvalidArgumentException
     */
    public function finDeLExerciceEnCours(Societe $societe, string $jour): string
    {
        return $this->exerciceDuJour($societe, $jour)[1];
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

    /**
     * Le premier et le dernier jour de l'exercice qui comprend ce jour :
     * celui que fixe le dernier changement dont l'exercice commence ce
     * jour-là ou avant, tant que sa fin n'est pas passée ; après elle, celui
     * du mois qui suit ce changement, qui ne commence pas avant le
     * lendemain de cette fin ; avant le premier changement, celui du mois
     * que ce changement a quitté, ou du mois de la société sans changement.
     *
     * @return array{0: string, 1: string}
     *
     * @throws InvalidArgumentException
     */
    private function exerciceDuJour(Societe $societe, string $jour): array
    {
        $changements = ChangementFinExercice::query()->withoutGlobalScope(SocieteScope::class)->where('societe_id', $societe->getKey())->orderBy('id')->get()->values();
        $rang = $changements->filter(fn (ChangementFinExercice $changement): bool => $changement->debut_exercice_en_cours <= $jour)->keys()->last();

        if ($rang === null) {
            return self::exerciceAuMois($changements->first()->ancien_mois ?? (int) $societe->mois_fin_exercice, $jour, null);
        }

        $changement = ValeurRequise::de($changements->get($rang), 'le changement de fin d\'exercice du jour');

        if ($jour <= $changement->fin_exercice_en_cours_apres) {
            return [$changement->debut_exercice_en_cours, $changement->fin_exercice_en_cours_apres];
        }

        $moisSuivant = $changements->get($rang + 1)->ancien_mois ?? (int) $societe->mois_fin_exercice;

        return self::exerciceAuMois($moisSuivant, $jour, Calendrier::ajouterJours($changement->fin_exercice_en_cours_apres, 1));
    }

    /**
     * L'exercice qui finit à ce mois et comprend ce jour : du lendemain de
     * la fin de ce mois qui le précède, ou de $auPlusTot s'il vient après,
     * jusqu'à la fin de ce mois qui tombe ce jour-là ou après.
     *
     * @return array{0: string, 1: string}
     *
     * @throws InvalidArgumentException
     */
    private static function exerciceAuMois(int $mois, string $jour, ?string $auPlusTot): array
    {
        $debut = Calendrier::ajouterJours(self::finDeMoisAvant($mois, $jour), 1);

        return [$auPlusTot !== null && $auPlusTot > $debut ? $auPlusTot : $debut, self::finDeMoisAuPlusTot($mois, $jour)];
    }

    private static function dernierJour(int $annee, int $mois): string
    {
        $premierDuMoisSuivant = sprintf('%04d-%02d-01', $mois === 12 ? $annee + 1 : $annee, $mois === 12 ? 1 : $mois + 1);

        return Calendrier::ajouterJours($premierDuMoisSuivant, -1);
    }
}
