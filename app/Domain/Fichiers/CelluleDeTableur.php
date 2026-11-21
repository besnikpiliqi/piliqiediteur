<?php

namespace App\Domain\Fichiers;

/**
 * Un texte écrit dans un fichier qu'ouvre un tableur (le CSV du
 * téléchargement des documents par période, l'export du journal des
 * événements, l'Excel de l'état des charges) : un texte qui commence par
 * =, +, -, @, une tabulation ou un retour chariot serait évalué comme une
 * formule à l'ouverture (injection de formule, jusqu'à l'exfiltration du
 * fichier) ; il est précédé d'une apostrophe, qui le fait lire comme du
 * texte. Les montants, écrits par PILIQI, ne passent pas par ici : un
 * montant négatif reste un nombre.
 */
final class CelluleDeTableur
{
    public static function texte(string $valeur): string
    {
        return preg_match('/^[=+\-@\t\r]/', $valeur) === 1 ? "'".$valeur : $valeur;
    }
}
