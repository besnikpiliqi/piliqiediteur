<?php

namespace App\Domain\Facturation\Enums;

use App\Domain\Tva\Services\TerritoireFiscal;
use Illuminate\Support\Str;

/**
 * Le territoire de TVA où un magasin établi en France vend : il fixe son
 * taux normal, proposé par défaut (20 % en métropole et en Corse, 8,5 % en
 * Guadeloupe, en Martinique et à La Réunion, art. 296 du CGI ; aucune TVA
 * en Guyane et à Mayotte, art. 294 du CGI), et les taux en vigueur qu'un
 * taux saisi librement est censé reprendre (voir aideSiTauxInhabituel()).
 *
 * Il se déduit de l'adresse du magasin (voir depuisAdresse()). Les
 * collectivités d'outre-mer (Saint-Barthélemy, Saint-Martin,
 * Saint-Pierre-et-Miquelon, Polynésie française, Nouvelle-Calédonie,
 * Wallis-et-Futuna) sont hors du territoire de TVA français, et le
 * logiciel ne gère pas encore la Guyane et Mayotte (voir
 * estPrisEnCharge()) : leurs taux propres viendront avec la table des
 * taux par territoire, à valider par un expert-comptable.
 */
enum TerritoireTva: string
{
    case Metropole = 'metropole';
    case Corse = 'corse';
    case AntillesReunion = 'antilles_reunion';
    case GuyaneMayotte = 'guyane_mayotte';

    /**
     * Le territoire d'une adresse en France, d'après son code postal : 20
     * pour la Corse ; le département d'outre-mer, 971 (Guadeloupe), 972
     * (Martinique) et 974 (La Réunion), 973 (Guyane) et 976 (Mayotte),
     * comme pour une livraison (voir TerritoireFiscal::departementDom()) ;
     * aucun pour une collectivité d'outre-mer (voir
     * TerritoireFiscal::estUneCollectiviteDOutreMer()) ni pour une adresse
     * hors de France. Tout autre code postal, ou aucun, est en métropole.
     */
    public static function depuisAdresse(?string $pays, ?string $codePostal): ?self
    {
        if (Str::upper(trim((string) $pays)) !== 'FR' || TerritoireFiscal::estUneCollectiviteDOutreMer('FR', $codePostal)) {
            return null;
        }

        return match (TerritoireFiscal::departementDom('FR', $codePostal)) {
            '971', '972', '974' => self::AntillesReunion,
            '973', '976' => self::GuyaneMayotte,
            default => str_starts_with((string) preg_replace('/\s+/', '', (string) $codePostal), '20') ? self::Corse : self::Metropole,
        };
    }

    /**
     * Le logiciel gère-t-il un magasin de ce territoire ? Pas encore en
     * Guyane ni à Mayotte, où la TVA n'est pas applicable et où les
     * documents suivent d'autres règles (voir CreerEtablissementAction).
     */
    public function estPrisEnCharge(): bool
    {
        return $this !== self::GuyaneMayotte;
    }

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
