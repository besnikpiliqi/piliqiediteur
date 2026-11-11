<?php

declare(strict_types=1);

namespace App\Domain\Facturation\Services;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\NumberSyntax;
use InvalidArgumentException;

/**
 * Un nombre saisi (formulaire, API, fabrique de test) lu en centièmes
 * entiers, comme le calcul les attend (voir CalculateurLigne) : un prix ou
 * un montant en centimes, un taux ou une remise en centièmes de point --
 * 25 vaut 2500, « 9.99 » vaut 999, « 5.5 » vaut 550.
 *
 * Une saisie plus précise que le centième s'arrondit une seule fois au
 * plus proche, un demi en s'éloignant de zéro (« 8.325 » vaut 833), comme
 * l'enregistrement dans une colonne decimal(12,2) ou decimal(5,2) ; un
 * coût, de même au dix-millième d'euro, comme une colonne decimal(14,4)
 * (voir enDixMilliemes()). Un montant ou un prix d'achat recopié de la
 * facture d'un fournisseur ne s'arrondit jamais : un montant au centime,
 * un prix d'achat à six décimales au plus, et une saisie plus précise est
 * refusée (voir montantAuCentime() et enMillioniemes()).
 *
 * Une saisie se lit par ses chiffres, exactement, jamais par un nombre à
 * virgule flottante, et ne s'arrondit qu'une fois, à sa conversion : une
 * écriture scientifique (« 1e3 ») passe par BigDecimal (brick/math), qui
 * la développe sans rien arrondir. Un nombre qui arrive déjà à virgule
 * flottante, que seuls un composant de l'écran, une requête JSON ou un
 * test peuvent transmettre, se lit aussitôt par son écriture la plus
 * courte, celle qui le redonne exactement et qu'avait la saisie
 * transmise (voir ecritureCourte()) : le bruit binaire s'efface (8.325
 * vaut réellement 8,32499999999999982... et s'écrit « 8.325 », 833
 * centimes) sans autre arrondi (8.3249996 vaut 832 centimes, comme
 * « 8.3249996 »), le calcul lui-même refusant un tel nombre (voir
 * CalculateurLigne). Une valeur qui n'est pas un nombre est refusée,
 * jamais lue comme zéro.
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
     * Le plus de chiffres qu'une écriture scientifique développe : bien
     * au-delà des douze chiffres entiers et des six décimales de toute
     * colonne, et des dix-sept chiffres significatifs d'un nombre à virgule
     * flottante écrit entre 1e-22 et 1e22, assez peu pour qu'une saisie
     * comme « 1e1000000000 » ne s'écrive jamais en entier.
     */
    private const CHIFFRES_D_UNE_ECRITURE_SCIENTIFIQUE = 40;

    /**
     * @throws InvalidArgumentException
     */
    public static function enCentiemes(mixed $saisie): int
    {
        $centiemes = self::arrondie($saisie, 2);

        if (abs($centiemes) >= self::CENTIEMES_MAXIMUM) {
            throw new InvalidArgumentException('Montant hors limites.');
        }

        return $centiemes;
    }

    /**
     * Un coût saisi, ou relu d'une colonne decimal(14,4) (le coût moyen
     * d'un produit, le coût d'un mouvement de stock), en dix-millièmes
     * d'euro : arrondi une seule fois au dix-millième le plus proche, un
     * demi en s'éloignant de zéro -- « 1.003 » vaut 10030, « 0.12345 »
     * vaut 1235 -- comme une saisie au centième (voir enCentiemes()).
     *
     * @throws InvalidArgumentException
     */
    public static function enDixMilliemes(mixed $saisie): int
    {
        return self::arrondie($saisie, 4);
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

        $centiemes = self::enUnites($entiers, $decimales, 2);

        return $negatif ? -$centiemes : $centiemes;
    }

    /**
     * Un prix d'achat ou un coût d'inventaire saisi (décision de
     * l'éditeur du 02/11/2026, T2), en millionièmes d'euro : tel que le
     * fournisseur l'écrit, six décimales au plus, jamais arrondi en
     * silence -- « 0.1234 » vaut 123400, « 0.1234567 » est refusé
     * (REFUS_PRIX_D_ACHAT_TROP_PRECIS). Un nombre transmis à virgule
     * flottante se lit, comme toute saisie, par son écriture la plus
     * courte : 0.1234567 est refusé comme « 0.1234567 ».
     *
     * @throws InvalidArgumentException
     */
    public static function enMillioniemes(mixed $saisie): int
    {
        [$negatif, $entiers, $decimales] = self::lire($saisie);

        if (rtrim(substr($decimales, self::DECIMALES_D_UN_PRIX_D_ACHAT), '0') !== '') {
            throw new InvalidArgumentException(self::REFUS_PRIX_D_ACHAT_TROP_PRECIS);
        }

        $millioniemes = self::enUnites($entiers, $decimales, self::DECIMALES_D_UN_PRIX_D_ACHAT);

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
            is_float($saisie) && is_finite($saisie) => self::ecritureCourte($saisie),
            is_string($saisie) => trim($saisie),
            default => throw new InvalidArgumentException('Nombre illisible.'),
        };

        if (preg_match('/^-?(\d+(\.\d*)?|\.\d+)$/', $texte) !== 1) {
            if (! is_numeric($texte)) {
                throw new InvalidArgumentException("« {$texte} » n'est pas un nombre.");
            }

            $texte = self::ecritureDecimale($texte);
        }

        // Le point ajouté donne toujours une partie décimale, vide quand la
        // saisie n'en écrit pas.
        [$entiers, $decimales] = explode('.', ltrim($texte, '-').'.');
        $entiers = ltrim($entiers, '0');

        // Plus de 12 chiffres dépassent la borne de toute façon, et leur
        // conversion en entier saturerait en silence.
        if (strlen($entiers) > 12) {
            throw new InvalidArgumentException('Montant hors limites.');
        }

        return [str_starts_with($texte, '-'), $entiers, $decimales];
    }

    /**
     * Une saisie en unités de 10^-$nombreDeDecimales, arrondie une seule
     * fois à la plus proche, un demi en s'éloignant de zéro : la décimale
     * qui suit la dernière gardée décide seule de l'arrondi, les suivantes
     * ne pouvant que l'éloigner du demi.
     *
     * @throws InvalidArgumentException
     */
    private static function arrondie(mixed $saisie, int $nombreDeDecimales): int
    {
        [$negatif, $entiers, $decimales] = self::lire($saisie);

        $unites = self::enUnites($entiers, $decimales, $nombreDeDecimales) + (($decimales[$nombreDeDecimales] ?? '0') >= '5' ? 1 : 0);

        return $negatif ? -$unites : $unites;
    }

    /**
     * L'écriture la plus courte qui redonne exactement ce nombre à virgule
     * flottante, en notation scientifique : celle de la saisie que l'écran,
     * le JSON ou le test a transmise -- 8.3249996 s'écrit « 8.3249996e+0 »,
     * 8.325, qui vaut réellement 8,32499999999999982..., « 8.325e+0 »,
     * 0.1 + 0.2 « 3.0000000000000004e-1 ». Elle se lit ensuite exactement
     * (voir ecritureDecimale()), sans aucun arrondi avant celui de la
     * conversion, et ne dépend d'aucun réglage de PHP (precision,
     * serialize_precision) ; dix-sept chiffres significatifs redonnent
     * toujours le nombre.
     */
    private static function ecritureCourte(float $nombre): string
    {
        for ($decimales = 0; $decimales < 16; $decimales++) {
            $ecriture = sprintf('%.'.$decimales.'e', $nombre);

            if ((float) $ecriture === $nombre) {
                return $ecriture;
            }
        }

        return sprintf('%.16e', $nombre);
    }

    /**
     * L'écriture décimale d'un nombre écrit en notation scientifique, que
     * PHP reconnaît comme un nombre (« 1e3 », « -2.5E1 », « +12 »), ou d'un
     * nombre transmis à virgule flottante (voir ecritureCourte()) : lu
     * exactement par BigDecimal, sans passer par un nombre à virgule
     * flottante, avec toutes ses décimales -- « 8.3249996e0 » s'écrit
     * « 8.3249996 » --, si bien que la conversion qui suit arrondit une
     * seule fois et que montantAuCentime() et enMillioniemes() refusent
     * une décimale de trop au lieu de l'arrondir. Un nombre que ses
     * chiffres feraient dépasser toute colonne (« 1e400 », « 1e-50 ») est
     * refusé, jamais lu zéro.
     *
     * @throws InvalidArgumentException
     */
    private static function ecritureDecimale(string $texte): string
    {
        try {
            return (string) BigDecimal::parse($texte, NumberSyntax::SCIENTIFIC, self::CHIFFRES_D_UNE_ECRITURE_SCIENTIFIQUE);
        } catch (MathException) {
            throw new InvalidArgumentException('Montant hors limites.');
        }
    }

    /**
     * Ces chiffres entiers suivis des $decimales premières décimales,
     * complétées de zéros, lus d'un bloc : en unités de 10^-$decimales
     * (« 12 » et « 5 » font 1250 centièmes).
     */
    private static function enUnites(string $entiers, string $decimales, int $nombreDeDecimales): int
    {
        return (int) ($entiers.str_pad(substr($decimales, 0, $nombreDeDecimales), $nombreDeDecimales, '0'));
    }
}
