<?php

namespace App\Domain\TableauDeBord\Support;

use App\Domain\Calendrier\Calendrier;
use InvalidArgumentException;

/**
 * Les mois du tableau de bord, écrits « AAAA-MM » : un mois du calendrier
 * de son magasin, sans fuseau (voir Calendrier), lu sur la date d'un
 * document (« 2026-01-31 » est en « 2026-01 »).
 */
final class Mois
{
    private const NOMS_COURTS = [1 => 'janv.', 2 => 'févr.', 3 => 'mars', 4 => 'avr.', 5 => 'mai', 6 => 'juin', 7 => 'juil.', 8 => 'août', 9 => 'sept.', 10 => 'oct.', 11 => 'nov.', 12 => 'déc.'];

    /**
     * Le mois d'un jour « AAAA-MM-JJ ».
     */
    public static function duJour(string $jour): string
    {
        return substr($jour, 0, 7);
    }

    /**
     * Ce mois décalé de $mois mois (négatif : en arrière).
     */
    public static function decaler(string $mois, int $decalage): string
    {
        [$annee, $numero] = self::lire($mois);
        $rang = $annee * 12 + ($numero - 1) + $decalage;

        return sprintf('%04d-%02d', intdiv($rang, 12), $rang % 12 + 1);
    }

    /**
     * Les mois du premier au dernier, compris, dans l'ordre.
     *
     * @return list<string>
     */
    public static function du(string $premier, string $dernier): array
    {
        $liste = [];

        for ($mois = $premier; $mois <= $dernier; $mois = self::decaler($mois, 1)) {
            $liste[] = $mois;
        }

        return $liste;
    }

    /**
     * Son numéro dans l'année, de 1 à 12.
     */
    public static function numero(string $mois): int
    {
        return self::lire($mois)[1];
    }

    public static function annee(string $mois): int
    {
        return self::lire($mois)[0];
    }

    /**
     * « janv. 2026 », pour l'axe d'un graphique et son tableau.
     */
    public static function libelle(string $mois): string
    {
        [$annee, $numero] = self::lire($mois);

        return self::NOMS_COURTS[$numero].' '.$annee;
    }

    /**
     * « janv. », « déc. » : le nom court du mois, sans l'année, pour l'étiquette
     * d'une colonne et le jour d'un achat (« 6 déc. »).
     */
    public static function nomCourt(string $mois): string
    {
        return self::NOMS_COURTS[self::lire($mois)[1]];
    }

    /**
     * « Janvier 2026 », pour l'intitulé d'une barre et d'une ligne de
     * tableau.
     */
    public static function intitule(string $mois): string
    {
        return Calendrier::nomDuMois(self::numero($mois)).' '.self::annee($mois);
    }

    /**
     * Le premier jour de ce mois, « AAAA-MM-01 ».
     */
    public static function premierJour(string $mois): string
    {
        self::lire($mois);

        return $mois.'-01';
    }

    /**
     * Le dernier jour de ce mois, « AAAA-MM-28 » à « AAAA-MM-31 ».
     */
    public static function dernierJour(string $mois): string
    {
        return Calendrier::ajouterJours(self::premierJour(self::decaler($mois, 1)), -1);
    }

    /**
     * Le même quantième un an plus tôt, ramené au dernier jour du mois quand
     * il n'y existe pas : le 29 février d'une année bissextile est le 28
     * février de l'année d'avant.
     */
    public static function memeJourUnAnPlusTot(string $jour): string
    {
        $mois = self::decaler(self::duJour($jour), -12);

        return min($mois.'-'.substr($jour, 8, 2), self::dernierJour($mois));
    }

    /**
     * @return array{0: int, 1: int}
     */
    private static function lire(string $mois): array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $mois, $parties) !== 1 || (int) $parties[2] < 1 || (int) $parties[2] > 12) {
            throw new InvalidArgumentException('Mois illisible : « AAAA-MM » est attendu.');
        }

        return [(int) $parties[1], (int) $parties[2]];
    }
}
