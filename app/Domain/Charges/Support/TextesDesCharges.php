<?php

namespace App\Domain\Charges\Support;

use App\Domain\Calendrier\Calendrier;

/**
 * Les mots communs de la page des charges et de son état téléchargeable
 * (voir PageDesCharges et EtatDesCharges) : le nom des charges qui ne sont
 * d'aucun magasin, et le mois « AAAA-MM » en toutes lettres ou abrégé.
 */
final class TextesDesCharges
{
    public const TOUTE_LA_SOCIETE = 'Toute la société';

    /**
     * La mention, en orange, sous la TVA d'une charge dont le fournisseur est
     * établi hors de France : l'écran, la liste et l'état téléchargé (PDF et
     * Excel) la disent de même (décision de l'éditeur du 20/11/2026).
     */
    public const TVA_AUTOLIQUIDEE = 'TVA autoliquidée';

    /** Les mois abrégés de l'en-tête du tableau des mois. */
    private const ABREVIATIONS_DES_MOIS = ['Janv.', 'Févr.', 'Mars', 'Avr.', 'Mai', 'Juin', 'Juil.', 'Août', 'Sept.', 'Oct.', 'Nov.', 'Déc.'];

    /**
     * Le mois « AAAA-MM » en toutes lettres (« mars 2026 »), tel quel s'il
     * n'est pas un mois.
     */
    public static function nomDuMois(string $mois): string
    {
        return preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/D', $mois, $parties) === 1
            ? mb_strtolower(Calendrier::nomDuMois((int) $parties[2])).' '.$parties[1]
            : $mois;
    }

    /**
     * L'abréviation du mois « AAAA-MM » dans l'en-tête du tableau des mois
     * (« Janv. », « Févr. »…), tel quel s'il n'est pas un mois.
     */
    public static function abregeDuMois(string $mois): string
    {
        return preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $mois, $parties) === 1
            ? self::ABREVIATIONS_DES_MOIS[(int) $parties[1] - 1]
            : $mois;
    }

    /**
     * Un texte qui commence par une minuscule (« année 2026 »).
     */
    public static function enMinuscule(string $texte): string
    {
        return mb_strtolower(mb_substr($texte, 0, 1)).mb_substr($texte, 1);
    }
}
