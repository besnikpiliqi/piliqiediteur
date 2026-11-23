<?php

namespace App\Domain\Facturation\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Ce qu'une ligne vend : un bien (un produit du catalogue) ou un service
 * (un service du magasin, la main-d'œuvre), tiré de la ligne elle-même
 * (voir ConstructeurLigneDocument). La facture en tire sa catégorie
 * d'opération (voir CategorieOperation), et la déclaration des ventes aux
 * particuliers (e-reporting) sépare les biens des services.
 */
enum NatureLigne: string implements HasLabel
{
    case Bien = 'bien';
    case Service = 'service';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bien => 'Bien',
            self::Service => 'Service',
        };
    }
}
