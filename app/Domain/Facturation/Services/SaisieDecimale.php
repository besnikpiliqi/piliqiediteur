<?php

namespace App\Domain\Facturation\Services;

use App\Domain\Tva\Services\CalculateurLigne;
use InvalidArgumentException;

/**
 * Un nombre saisi (formulaire, API, fabrique de test) lu en centièmes
 * entiers, comme le calcul les attend (voir CalculateurLigne) : un prix ou
 * un montant en centimes, un taux ou une remise en centièmes de point --
 * 25 vaut 2500, « 9.99 » vaut 999, « 5.5 » vaut 550.
 *
 * Une saisie plus précise que le centième s'arrondit une seule fois au
 * plus proche, un demi en s'éloignant de zéro (« 8.325 » vaut 833), comme
 * l'enregistrement dans une colonne decimal(12,2) ou decimal(5,2). Un
 * nombre transmis à virgule flottante (un formulaire, un test) se lit par
 * son écriture décimale à six décimales, qui efface le bruit binaire
 * (8.325 vaut réellement 8,32499999999999982...) : c'est la seule porte
 * d'entrée d'un tel nombre, que le calcul lui-même refuse. Une valeur qui
 * n'est pas un nombre est refusée, jamais lue comme zéro.
 */
final class SaisieDecimale
{
    /**
     * La borne de CalculateurLigne : au-delà de toute colonne.
     */
    private const CENTIEMES_MAXIMUM = 100_000_000_000_000;

    /**
     * @throws InvalidArgumentException
     */
    public static function enCentiemes(mixed $saisie): int
    {
        $texte = match (true) {
            is_int($saisie) => (string) $saisie,
            is_float($saisie) && is_finite($saisie) => sprintf('%.6F', $saisie),
            is_string($saisie) => trim($saisie),
            default => throw new InvalidArgumentException('Nombre illisible.'),
        };

        if (preg_match('/^-?(\d+(\.\d*)?|\.\d+)$/D', $texte) !== 1) {
            if (! is_numeric($texte)) {
                throw new InvalidArgumentException("« {$texte} » n'est pas un nombre.");
            }

            // Notation scientifique (« 1e3 ») : son écriture décimale. Un
            // nombre trop grand pour un flottant (« 1e400 ») n'en a pas, et
            // sprintf l'écrirait « INF », qui se lirait zéro.
            $nombre = (float) $texte;

            if (! is_finite($nombre)) {
                throw new InvalidArgumentException('Montant hors limites.');
            }

            $texte = sprintf('%.6F', $nombre);
        }

        [$entiers, $decimales] = array_pad(explode('.', ltrim($texte, '-')), 2, '');
        $entiers = ltrim($entiers, '0');

        // Plus de 12 chiffres dépassent la borne de toute façon, et leur
        // conversion en entier saturerait en silence.
        if (strlen($entiers) > 12) {
            throw new InvalidArgumentException('Montant hors limites.');
        }

        $decimales = str_pad($decimales, 3, '0');
        $centiemes = (int) $entiers * 100 + (int) substr($decimales, 0, 2) + ($decimales[2] >= '5' ? 1 : 0);

        if ($centiemes >= self::CENTIEMES_MAXIMUM) {
            throw new InvalidArgumentException('Montant hors limites.');
        }

        return str_starts_with($texte, '-') ? -$centiemes : $centiemes;
    }
}
