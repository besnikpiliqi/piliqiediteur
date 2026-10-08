<?php

namespace App\Domain\Facturation\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Le prix unitaire réellement saisi sur une ligne, qui fait foi pour son
 * calcul (voir CalculateurLigne::calculer()) : 40 € TTC x 2 doit donner
 * 80,00 € TTC, et 10 x 1,18 € HT doit donner 11,80 € HT + 2,36 € de TVA =
 * 14,16 € TTC -- quel que soit le client ou le document. Un prix TTC a
 * aussi son PU HT, sa conversion au centime ; un prix HT n'a pas de PU TTC.
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
