<?php

namespace App\Domain\Tva\Services;

use Illuminate\Support\Str;

/**
 * Le n° de TVA intracommunautaire d'une entreprise, magasin, client ou
 * fournisseur : les deux lettres de son pays, puis 2 à 13 chiffres ou
 * lettres. Il se saisit au besoin avec les espaces, points ou tirets qui
 * en séparent souvent les chiffres, et s'enregistre sans eux (voir
 * NumeroTvaNormalise), partout de la même façon. Seul son format est
 * contrôlé à la saisie (voir NumeroTvaAuFormat) : celui d'un client se
 * vérifie ensuite sur VIES, à la demande (voir VerifierNumeroTvaAction),
 * et un n° français n'est pas comparé au SIREN de sa propre fiche,
 * puisqu'un groupe TVA a un n° sans lien avec le SIREN de chacun de ses
 * membres.
 */
class NumeroTva
{
    private const FORMAT = '/^[A-Z]{2}[0-9A-Z]{2,13}$/';

    /**
     * Le n° tel qu'il s'enregistre : en majuscules, sans les espaces, points
     * ni tirets qui en séparent souvent les chiffres (« be 0123.456.789 »
     * est BE0123456789).
     */
    public static function normaliser(string $saisie): string
    {
        return Str::upper((string) preg_replace('/[\s.\-]+/u', '', $saisie));
    }

    /**
     * Cette saisie, une fois normalisée, est-elle au format d'un n° de TVA ?
     */
    public static function estAuFormat(mixed $saisie): bool
    {
        return is_string($saisie) && preg_match(self::FORMAT, self::normaliser($saisie)) === 1;
    }
}
