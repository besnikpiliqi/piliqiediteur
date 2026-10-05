<?php

namespace App\Domain\Facturation\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Le prix unitaire réellement saisi sur une ligne, qui fait foi pour son
 * calcul (voir CalculateurLigne::calculer()) : 40 € TTC x 2 doit donner
 * 80,00 € TTC, et 8,33 € HT x 3 doit donner 24,99 € HT -- quel que soit le
 * client ou le document. L'autre prix n'en est que la conversion au
 * centime, pour l'affichage.
 */
enum PrixReference: string implements HasLabel
{
    case Ht = 'ht';
    case Ttc = 'ttc';

    public function getLabel(): string
    {
        return match ($this) {
            self::Ht => 'PU HT',
            self::Ttc => 'PU TTC',
        };
    }

    /**
     * Prix de référence explicitement transmis (formulaire), sinon déduit
     * du seul prix fourni (appel d'API, import), sinon celui par défaut
     * (prix catalogue du produit).
     *
     * @param  array<string, mixed>  $donnees
     */
    public static function depuisSaisie(array $donnees, self $parDefaut): self
    {
        $explicite = $donnees['prix_reference'] ?? null;

        if ($explicite instanceof self) {
            return $explicite;
        }

        if (filled($explicite) && ($reference = self::tryFrom((string) $explicite)) !== null) {
            return $reference;
        }

        $htFourni = filled($donnees['prix_unitaire_ht'] ?? null);
        $ttcFourni = filled($donnees['prix_unitaire_ttc'] ?? null);

        return match (true) {
            $ttcFourni && ! $htFourni => self::Ttc,
            $htFourni && ! $ttcFourni => self::Ht,
            default => $parDefaut,
        };
    }
}
