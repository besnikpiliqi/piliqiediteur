<?php

declare(strict_types=1);

namespace App\Domain\Catalogue\Enums;

use InvalidArgumentException;

/**
 * La formule avec laquelle le barème d'un magasin ramène son prix de vente
 * TTC à un prix rond (Paramètres > Prix de vente ; décision de l'éditeur du
 * 22/11/2026) : aucune par défaut ; un prix en 9 (la dizaine d'euros la plus
 * proche, moins 1 €) ; l'euro le plus proche ; le multiple de 5 € le plus
 * proche. Le multiple le plus proche se cherche sur le prix EXACT (le prix
 * d'achat au dix-millième plus le bénéfice, fois 1 + TVA), jamais sur le
 * prix déjà arrondi au centime, un prix pile au milieu montant ; un prix
 * qui deviendrait 0 € (ou, pour un prix en 9, qui est sous 5 €) reste tel
 * quel, au centime. Tout se calcule en centimes entiers (voir
 * BaremePrixDeVente::prixDeVente() et CalculateurLigne::prixTtcArrondi()).
 */
enum ArrondiDuPrixDeVente: string
{
    case Aucun = 'aucun';
    case UnPrixEn9 = 'x9';
    case EuroLePlusProche = 'euro';
    case MultipleDeCinq = 'cinq';

    public const REFUS_FORMULE_INCONNUE = 'Cette formule d\'arrondi n\'existe pas.';

    /**
     * Les trois formules que la page propose, dans son ordre.
     *
     * @return list<self>
     */
    public static function formules(): array
    {
        return [self::UnPrixEn9, self::EuroLePlusProche, self::MultipleDeCinq];
    }

    /**
     * La formule que la page enregistre : aucune quand la case est
     * décochée, sinon l'une des trois formules.
     *
     * @throws InvalidArgumentException une formule inconnue, ou « aucun » avec la case cochée
     */
    public static function choisie(bool $arrondir, mixed $formule): self
    {
        if (! $arrondir) {
            return self::Aucun;
        }

        $choisie = is_string($formule) ? self::tryFrom($formule) : null;

        return $choisie !== null && $choisie !== self::Aucun ? $choisie : throw new InvalidArgumentException(self::REFUS_FORMULE_INCONNUE);
    }

    public function libelle(): string
    {
        return match ($this) {
            self::Aucun => 'Aucun arrondi',
            self::UnPrixEn9 => 'Un prix en 9',
            self::EuroLePlusProche => 'L\'euro le plus proche',
            self::MultipleDeCinq => 'Le multiple de 5 € le plus proche',
        };
    }

    /**
     * Le pas, en centimes, dont le prix est le multiple le plus proche du
     * prix exact ; null sans arrondi.
     */
    public function pasEnCentimes(): ?int
    {
        return match ($this) {
            self::Aucun => null,
            self::UnPrixEn9 => 1000,
            self::EuroLePlusProche => 100,
            self::MultipleDeCinq => 500,
        };
    }

    /**
     * Le prix de vente que donne le multiple du pas le plus proche du prix
     * exact (en centimes) ; null quand le prix reste tel quel : un prix en 9
     * sous 5 € (multiple à 0), un prix qui deviendrait 0 € aux deux autres
     * formules, ou aucune formule.
     */
    public function arrondir(int $multipleLePlusProche): ?int
    {
        return match ($this) {
            self::Aucun => null,
            self::UnPrixEn9 => $multipleLePlusProche >= 1000 ? $multipleLePlusProche - 100 : null,
            self::EuroLePlusProche, self::MultipleDeCinq => $multipleLePlusProche > 0 ? $multipleLePlusProche : null,
        };
    }

    /**
     * Pourquoi le prix reste tel quel (phrase grise de l'essai), ou null
     * sans arrondi.
     */
    public function raisonDuPrixLaisseTel(): ?string
    {
        return match ($this) {
            self::Aucun => null,
            self::UnPrixEn9 => 'sous 5 €, le prix reste tel quel',
            self::EuroLePlusProche, self::MultipleDeCinq => 'l\'arrondi donnerait 0 €, le prix reste tel quel',
        };
    }
}
