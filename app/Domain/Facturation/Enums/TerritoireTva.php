<?php

namespace App\Domain\Facturation\Enums;

/**
 * Le territoire de TVA où un magasin établi en France vend : il fixe son
 * taux normal, proposé par défaut (20 % en métropole et en Corse, 8,5 % en
 * Guadeloupe, en Martinique et à La Réunion, art. 296 du CGI ; aucune TVA
 * en Guyane et à Mayotte, art. 294 du CGI), et les taux en vigueur qu'un
 * taux saisi librement est censé reprendre (voir aideSiTauxInhabituel()).
 *
 * Il se déduit du territoire du magasin (voir Territoire::tva()), où la
 * Guadeloupe, la Martinique et La Réunion ont les mêmes taux. Les
 * collectivités d'outre-mer (Saint-Barthélemy, Saint-Martin,
 * Saint-Pierre-et-Miquelon, Polynésie française, Nouvelle-Calédonie,
 * Wallis-et-Futuna) sont hors du territoire de TVA français, et le
 * logiciel ne gère pas encore la Guyane et Mayotte (voir
 * Territoire::estPrisEnCharge()) : leurs taux propres viendront avec la
 * table des taux par territoire, à valider par un expert-comptable.
 */
enum TerritoireTva: string
{
    case Metropole = 'metropole';
    case Corse = 'corse';
    case AntillesReunion = 'antilles_reunion';
    case GuyaneMayotte = 'guyane_mayotte';

    /**
     * Le taux proposé par défaut pour une vente (B2).
     */
    public function tauxNormal(): float
    {
        return match ($this) {
            self::Metropole, self::Corse => 20.0,
            self::AntillesReunion => 8.5,
            self::GuyaneMayotte => 0.0,
        };
    }

    /**
     * Les taux en vigueur sur le territoire, du plus fort au plus faible
     * (art. 278 à 281 nonies, 296 et 297 du CGI).
     *
     * @return list<float>
     */
    public function tauxEnVigueur(): array
    {
        return match ($this) {
            self::Metropole => [20.0, 10.0, 5.5, 2.1],
            self::Corse => [20.0, 13.0, 10.0, 5.5, 2.1, 0.9],
            self::AntillesReunion => [8.5, 2.1, 1.75, 1.05],
            self::GuyaneMayotte => [0.0],
        };
    }

    /**
     * « en métropole », pour dire où vend le magasin.
     */
    public function lieu(): string
    {
        return match ($this) {
            self::Metropole => 'en métropole',
            self::Corse => 'en Corse',
            self::AntillesReunion => 'en Guadeloupe, en Martinique ou à La Réunion',
            self::GuyaneMayotte => 'en Guyane ou à Mayotte',
        };
    }

    /**
     * Le taux se saisit librement (B2) : un taux saisi qui n'est pas en
     * vigueur sur le territoire n'est pas refusé, une aide invite seulement
     * à vérifier la saisie (X8). Rien tant que le taux n'est pas un nombre.
     */
    public function aideSiTauxInhabituel(mixed $taux): ?string
    {
        if (! is_numeric($taux)) {
            return null;
        }

        $enCentiemes = fn (float $pourcentage): int => (int) round($pourcentage * 100);

        if (in_array($enCentiemes((float) $taux), array_map($enCentiemes, $this->tauxEnVigueur()), true)) {
            return null;
        }

        $enVigueur = implode(' ; ', array_map(fn (float $pourcentage): string => str_replace('.', ',', (string) $pourcentage), $this->tauxEnVigueur()));

        return "Taux inhabituel pour un magasin {$this->lieu()} (en vigueur : {$enVigueur} %) : vérifiez votre saisie.";
    }
}
