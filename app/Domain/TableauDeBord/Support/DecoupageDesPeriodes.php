<?php

namespace App\Domain\TableauDeBord\Support;

use App\Domain\Calendrier\Calendrier;
use App\Domain\TableauDeBord\Enums\DecoupageDuTableauDeBord;
use App\Domain\TableauDeBord\Enums\NiveauDeLaBarre;
use App\Domain\TableauDeBord\Enums\PeriodeDuTableauDeBord;

/**
 * Les barres d'un graphique par période pour ce découpage, cette période et
 * ce jour (choix de l'éditeur du 16/11/2026 ; décisions de Claude du
 * 22/11/2026, D76, sur ce qu'ils ne disent pas) :
 * - par mois : un mois par barre, ceux de la période ;
 * - par trimestre : quatre barres de trois mois. Sur « 12 derniers mois »,
 *   quatre blocs de trois mois, le dernier finissant ce mois-ci ; sur une
 *   année ou un exercice, T1 à T4 ;
 * - par année : les dix dernières années (ANNEES), la dernière étant
 *   l'année en cours ; quand la société ne clôt pas en décembre, chaque
 *   barre est un exercice, le dernier étant l'exercice en cours. La période
 *   n'y joue plus : l'écran montre ces dix ans ;
 * - le cumul depuis le début de l'année, ou de l'exercice, garde les barres
 *   de l'année ou de l'exercice entier, même sur « 12 derniers mois » : la
 *   carte part alors du début de l'année en cours (voir fenetreDuCumul()).
 * Un clic sur une barre la redécoupe (voir sousBarres()).
 */
final class DecoupageDesPeriodes
{
    /** Le nombre d'années, ou d'exercices, du découpage par année. */
    public const ANNEES = 10;

    private const JOURS = [1 => 'lundi', 2 => 'mardi', 3 => 'mercredi', 4 => 'jeudi', 5 => 'vendredi', 6 => 'samedi', 7 => 'dimanche'];

    /**
     * Le premier et le dernier mois de l'exercice qui contient ce mois : à
     * l'année civile quand il finit en décembre.
     *
     * @return array{0: string, 1: string}
     */
    public static function exercice(string $mois, int $moisDeFinDExercice): array
    {
        $fin = sprintf('%04d-%02d', Mois::numero($mois) <= $moisDeFinDExercice ? Mois::annee($mois) : Mois::annee($mois) + 1, $moisDeFinDExercice);

        return [Mois::decaler($fin, -11), $fin];
    }

    /**
     * Le premier et le dernier mois de ce que l'écran compte pour les
     * cartes qui répartissent la période (anneaux, classements, jours de la
     * semaine) : la période choisie, ou les dix dernières années quand le
     * découpage est l'année.
     *
     * @return array{0: string, 1: string}
     */
    public static function fenetre(DecoupageDuTableauDeBord $decoupage, PeriodeDuTableauDeBord $periode, string $aujourdhui, int $moisDeFinDExercice): array
    {
        if ($decoupage === DecoupageDuTableauDeBord::Annee) {
            $fin = self::exercice(Mois::duJour($aujourdhui), $moisDeFinDExercice)[1];

            return [Mois::decaler($fin, -12 * (self::ANNEES - 1) - 11), $fin];
        }

        return $periode->bornes($aujourdhui, $moisDeFinDExercice);
    }

    /**
     * Les mois du cumul depuis le début de l'année ou de l'exercice : sur
     * « 12 derniers mois », l'exercice en cours entier (l'année en cours
     * quand il finit en décembre) ; sur une autre période, cette période.
     *
     * @return array{0: string, 1: string}
     */
    public static function fenetreDuCumul(PeriodeDuTableauDeBord $periode, string $aujourdhui, int $moisDeFinDExercice): array
    {
        return $periode === PeriodeDuTableauDeBord::DouzeDerniersMois
            ? self::exercice(Mois::duJour($aujourdhui), $moisDeFinDExercice)
            : $periode->bornes($aujourdhui, $moisDeFinDExercice);
    }

    /**
     * Les barres de la carte.
     *
     * @return list<Barre>
     */
    public static function barres(DecoupageDuTableauDeBord $decoupage, PeriodeDuTableauDeBord $periode, bool $cumul, string $aujourdhui, int $moisDeFinDExercice): array
    {
        $periode = $periode->pourLaSociete($moisDeFinDExercice);

        if ($decoupage === DecoupageDuTableauDeBord::Annee) {
            $fin = self::exercice(Mois::duJour($aujourdhui), $moisDeFinDExercice)[1];
            $barres = [];

            for ($rang = self::ANNEES - 1; $rang >= 0; $rang--) {
                $dernier = Mois::decaler($fin, -12 * $rang);
                $barres[] = self::annee(Mois::decaler($dernier, -11), $dernier, $aujourdhui, $moisDeFinDExercice);
            }

            return $barres;
        }

        [$premier, $dernier] = $cumul ? self::fenetreDuCumul($periode, $aujourdhui, $moisDeFinDExercice) : $periode->bornes($aujourdhui, $moisDeFinDExercice);

        if ($decoupage === DecoupageDuTableauDeBord::Mois) {
            return array_map(fn (string $mois): Barre => self::mois($mois, $aujourdhui), Mois::du($premier, $dernier));
        }

        $glissant = ! $cumul && $periode === PeriodeDuTableauDeBord::DouzeDerniersMois;
        $parExercice = $moisDeFinDExercice !== 12 && ($periode->estUnExercice() || ($cumul && $periode === PeriodeDuTableauDeBord::DouzeDerniersMois));
        $barres = [];

        for ($rang = 0; $rang < 4; $rang++) {
            $barres[] = self::trimestre(Mois::decaler($premier, 3 * $rang), $rang, $glissant, $parExercice, $aujourdhui, $moisDeFinDExercice);
        }

        return $barres;
    }

    /**
     * Les barres plus fines d'une barre : les mois d'une année ou d'un
     * trimestre, les jours d'un mois ; rien pour un jour.
     *
     * @return list<Barre>
     */
    public static function sousBarres(Barre $barre, string $aujourdhui, int $moisDeFinDExercice): array
    {
        return match ($barre->niveau) {
            NiveauDeLaBarre::Annee, NiveauDeLaBarre::Trimestre => array_map(
                fn (string $mois): Barre => self::mois($mois, $aujourdhui),
                Mois::du(Mois::duJour($barre->premierJour), Mois::duJour($barre->dernierJour)),
            ),
            NiveauDeLaBarre::Mois => self::jours($barre, $aujourdhui),
            NiveauDeLaBarre::Jour => [],
        };
    }

    /**
     * Les jours d'une barre d'un mois.
     *
     * @return list<Barre>
     */
    private static function jours(Barre $mois, string $aujourdhui): array
    {
        $jours = [];

        for ($jour = $mois->premierJour; $jour <= $mois->dernierJour; $jour = Calendrier::ajouterJours($jour, 1)) {
            $jours[] = self::jour($jour, $aujourdhui);
        }

        return $jours;
    }

    private static function mois(string $mois, string $aujourdhui): Barre
    {
        return self::barre(NiveauDeLaBarre::Mois, Mois::premierJour($mois), Mois::dernierJour($mois), Mois::libelle($mois), Mois::intitule($mois), $aujourdhui);
    }

    /**
     * Un trimestre : « T1 2026 » (« T1 2025-26 » pour un exercice décalé),
     * ou, sur les douze derniers mois, le bloc de trois mois qui finit
     * ce mois-ci : « déc. 2025 à févr. 2026 ».
     */
    private static function trimestre(string $premier, int $rang, bool $glissant, bool $parExercice, string $aujourdhui, int $moisDeFinDExercice): Barre
    {
        $dernier = Mois::decaler($premier, 2);

        if ($glissant) {
            return self::barre(NiveauDeLaBarre::Trimestre, Mois::premierJour($premier), Mois::dernierJour($dernier), Mois::libelle($premier).' à '.Mois::libelle($dernier), Mois::intitule($premier).' à '.lcfirst(Mois::intitule($dernier)), $aujourdhui);
        }

        [$debutDeLExercice, $finDeLExercice] = self::exercice($premier, $moisDeFinDExercice);
        $annee = $parExercice ? self::nomDeLExercice($debutDeLExercice, $finDeLExercice, court: true) : (string) Mois::annee($premier);
        $ordinal = $rang === 0 ? '1er' : ($rang + 1).'e';

        return self::barre(
            NiveauDeLaBarre::Trimestre,
            Mois::premierJour($premier),
            Mois::dernierJour($dernier),
            'T'.($rang + 1).' '.$annee,
            $ordinal.' trimestre '.$annee.' ('.Mois::nomCourt($premier).'–'.Mois::nomCourt($dernier).')',
            $aujourdhui,
        );
    }

    /**
     * Une année civile (« 2026 »), ou un exercice décalé (« 2025-26 »,
     * « Exercice 2025-2026 (juil. 2025 à juin 2026) »).
     */
    private static function annee(string $premier, string $dernier, string $aujourdhui, int $moisDeFinDExercice): Barre
    {
        $civile = $moisDeFinDExercice === 12;

        return self::barre(
            NiveauDeLaBarre::Annee,
            Mois::premierJour($premier),
            Mois::dernierJour($dernier),
            self::nomDeLExercice($premier, $dernier, court: true),
            $civile ? 'Année '.Mois::annee($dernier) : 'Exercice '.self::nomDeLExercice($premier, $dernier, court: false).' ('.Mois::libelle($premier).' à '.Mois::libelle($dernier).')',
            $aujourdhui,
        );
    }

    private static function jour(string $jour, string $aujourdhui): Barre
    {
        $mois = Mois::duJour($jour);

        return self::barre(
            NiveauDeLaBarre::Jour,
            $jour,
            $jour,
            (string) (int) substr($jour, 8, 2),
            ucfirst(self::JOURS[CalculDesSeries::jourDeLaSemaine($jour)]).' '.(int) substr($jour, 8, 2).' '.mb_strtolower(Calendrier::nomDuMois(Mois::numero($mois))).' '.Mois::annee($mois),
            $aujourdhui,
        );
    }

    /**
     * Le nom d'un exercice : « 2026 » quand il est l'année civile,
     * « 2025-26 » (court) ou « 2025-2026 » sinon.
     */
    private static function nomDeLExercice(string $premier, string $dernier, bool $court): string
    {
        if (Mois::annee($premier) === Mois::annee($dernier)) {
            return (string) Mois::annee($dernier);
        }

        return Mois::annee($premier).'-'.($court ? substr((string) Mois::annee($dernier), 2) : (string) Mois::annee($dernier));
    }

    private static function barre(NiveauDeLaBarre $niveau, string $premierJour, string $dernierJour, string $etiquette, string $intitule, string $aujourdhui): Barre
    {
        return new Barre($niveau, $premierJour, $dernierJour, $etiquette, $intitule, $premierJour > $aujourdhui, $premierJour <= $aujourdhui && $dernierJour > $aujourdhui);
    }
}
