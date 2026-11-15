<?php

namespace App\Domain\Charges\Support;

use Illuminate\Support\Str;

/**
 * La clé sous laquelle se comparent le nom d'un type de charge, le nom
 * d'un fournisseur et un numéro de pièce : sans espaces autour, les
 * espaces répétés réduits à un seul, sans accents, en minuscules.
 * « SCI des Halles » et « sci  des halles », « Q-2026-05 » et « q-2026-05 »
 * sont les mêmes : un type ne se crée pas deux fois, une pièce ne se
 * saisit pas deux fois (voir ChargesDeLaSociete).
 */
final class CleDeComparaison
{
    public static function de(string $texte): string
    {
        return Str::lower(Str::ascii(Str::squish($texte)));
    }

    /**
     * La clé d'un texte facultatif : null pour un texte absent ou vide.
     */
    public static function ouNul(?string $texte): ?string
    {
        $cle = $texte === null ? '' : self::de($texte);

        return $cle === '' ? null : $cle;
    }
}
