<?php

namespace App\Domain\Facturation\Services;

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
 * montant ou un prix d'achat recopié de la facture d'un fournisseur ne
 * s'arrondit jamais : un montant au centime, un prix d'achat à six
 * décimales au plus, et une saisie plus précise est refusée (voir
 * montantAuCentime() et enMillioniemes()). Un nombre transmis à virgule
 * flottante (un formulaire, un test) se lit par son écriture décimale à
 * six décimales, qui efface le bruit binaire (8.325 vaut réellement
 * 8,32499999999999982...) : c'est la seule porte d'entrée d'un tel
 * nombre, que le calcul lui-même refuse. Une valeur qui n'est pas un
 * nombre est refusée, jamais lue comme zéro.
 */
final class SaisieDecimale
{
    /**
     * La borne de CalculateurLigne : au-delà de toute colonne.
     */
    private const CENTIEMES_MAXIMUM = 100_000_000_000_000;

    /**
     * Le plus de décimales qu'un prix d'achat ou un coût d'inventaire
     * s'écrit (décision de l'éditeur du 02/11/2026, T2), comme sa colonne
     * decimal(18,6).
     */
    public const DECIMALES_D_UN_PRIX_D_ACHAT = 6;

    public const REFUS_PRIX_D_ACHAT_TROP_PRECIS = 'Un prix d\'achat s\'écrit avec six décimales au plus, tel que le fournisseur l\'écrit.';

    public const REFUS_MONTANT_TROP_PRECIS = 'Un montant s\'écrit au centime, avec deux décimales au plus.';

    /**
     * @throws InvalidArgumentException
     */
    public static function enCentiemes(mixed $saisie): int
    {
        [$negatif, $entiers, $decimales] = self::lire($saisie);

        $decimales = str_pad($decimales, 3, '0');
        $centiemes = (int) $entiers * 100 + (int) substr($decimales, 0, 2) + ($decimales[2] >= '5' ? 1 : 0);

        if ($centiemes >= self::CENTIEMES_MAXIMUM) {
            throw new InvalidArgumentException('Montant hors limites.');
        }

        return $negatif ? -$centiemes : $centiemes;
    }

    /**
     * Un montant écrit sur une facture du fournisseur (le HT d'une ligne,
     * la TVA ou la base d'un taux, le total TTC saisi pour contrôle), en
     * centimes : au centime, jamais arrondi en silence -- « 6.005 » est
     * refusé (REFUS_MONTANT_TROP_PRECIS), « 6.0 » et « 6.000 » valent 600.
     * Sans arrondi, ses douze chiffres entiers au plus (voir lire()) le
     * laissent sous la borne du calcul.
     *
     * @throws InvalidArgumentException
     */
    public static function montantAuCentime(mixed $saisie): int
    {
        [$negatif, $entiers, $decimales] = self::lire($saisie);

        if (rtrim(substr($decimales, 2), '0') !== '') {
            throw new InvalidArgumentException(self::REFUS_MONTANT_TROP_PRECIS);
        }

        $centiemes = (int) $entiers * 100 + (int) str_pad(substr($decimales, 0, 2), 2, '0');

        return $negatif ? -$centiemes : $centiemes;
    }

    /**
     * Un prix d'achat ou un coût d'inventaire saisi (décision de
     * l'éditeur du 02/11/2026, T2), en millionièmes d'euro : tel que le
     * fournisseur l'écrit, six décimales au plus, jamais arrondi en
     * silence -- « 0.1234 » vaut 123400, « 0.1234567 » est refusé
     * (REFUS_PRIX_D_ACHAT_TROP_PRECIS). Un nombre transmis à virgule
     * flottante se lit, comme toute saisie, par son écriture décimale à
     * six décimales.
     *
     * @throws InvalidArgumentException
     */
    public static function enMillioniemes(mixed $saisie): int
    {
        [$negatif, $entiers, $decimales] = self::lire($saisie);

        if (rtrim(substr($decimales, self::DECIMALES_D_UN_PRIX_D_ACHAT), '0') !== '') {
            throw new InvalidArgumentException(self::REFUS_PRIX_D_ACHAT_TROP_PRECIS);
        }

        $millioniemes = (int) $entiers * 1_000_000 + (int) str_pad(substr($decimales, 0, self::DECIMALES_D_UN_PRIX_D_ACHAT), self::DECIMALES_D_UN_PRIX_D_ACHAT, '0');

        return $negatif ? -$millioniemes : $millioniemes;
    }

    /**
     * La saisie écrite en décimal : son signe, ses chiffres entiers, sans
     * zéro de tête, et ses décimales, telles qu'écrites.
     *
     * @return array{bool, string, string}
     *
     * @throws InvalidArgumentException
     */
    private static function lire(mixed $saisie): array
    {
        $texte = match (true) {
            is_int($saisie) => (string) $saisie,
            is_float($saisie) && is_finite($saisie) => sprintf('%.6F', $saisie),
            is_string($saisie) => trim($saisie),
            default => throw new InvalidArgumentException('Nombre illisible.'),
        };

        if (preg_match('/^-?(\d+(\.\d*)?|\.\d+)$/', $texte) !== 1) {
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

        return [str_starts_with($texte, '-'), $entiers, $decimales];
    }
}
