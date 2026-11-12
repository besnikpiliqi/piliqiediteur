<?php

namespace App\Domain\Fichiers;

/**
 * La mémoire permise à PHP, relevée avant une lecture dont la taille se
 * connaît d'avance et se borne plus haut (une photo décodée, voir
 * ReenregistrementDesImages ; la description d'un PDF, voir
 * AnalyseurDePdf) : une telle lecture ne fait jamais tomber la requête
 * faute de mémoire, quelle que soit la limite que PHP pose par défaut
 * (128 Mo).
 */
final class MemoireDePhp
{
    /**
     * La marge laissée au reste de la requête.
     */
    public const MARGE_EN_OCTETS = 64 * 1024 * 1024;

    /**
     * Relève la mémoire permise à PHP si elle ne suffit pas pour ces
     * octets de plus que ce que la requête occupe déjà, plus la marge ;
     * jamais ne la baisse.
     */
    public static function prevoir(int $octets): void
    {
        $limite = ini_get('memory_limit');
        $permise = $limite === false || $limite === '' || $limite === '-1' ? -1 : ini_parse_quantity($limite);
        $necessaire = memory_get_usage(true) + $octets + self::MARGE_EN_OCTETS;

        if ($permise !== -1 && $permise < $necessaire) {
            ini_set('memory_limit', (string) $necessaire);
        }
    }
}
