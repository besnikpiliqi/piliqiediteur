<?php

namespace Database\Seeders\Semis;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Les pièces que le semis dépose (facture d'un fournisseur, pièce d'une
 * charge, pièce de la vérification d'une société) : un vrai PDF d'une
 * page, propre (ni JavaScript, ni fichier joint), qui passe les contrôles
 * de tout fichier reçu (voir ControleDesFichiers), et qui dit son émetteur,
 * son objet et qu'il est fictif. Chaque pièce n'est rendue qu'une fois par
 * semis.
 */
final class PieceDuSemis
{
    /** @var array<string, string> */
    private static array $rendues = [];

    public static function pdf(string $emetteur, string $objet): string
    {
        return self::$rendues[$emetteur."\n".$objet] ??= Pdf::loadHTML('<html><body style="font-family: DejaVu Sans"><h1>'.e($emetteur).'</h1><p>'.e($objet).'</p><p>Document fictif du semis de PILIQI.</p></body></html>')->setPaper('a4')->output();
    }
}
