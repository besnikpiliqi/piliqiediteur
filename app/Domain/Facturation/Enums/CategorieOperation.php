<?php

namespace App\Domain\Facturation\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Mention obligatoire d'une facture (art. 242 nonies A de l'annexe II au
 * CGI) : livraison de biens, prestation de services, ou les deux --
 * selon la nature de ses lignes (voir NatureLigne).
 */
enum CategorieOperation: string implements HasLabel
{
    case LivraisonDeBiens = 'livraison_de_biens';
    case PrestationDeServices = 'prestation_de_services';
    case LesDeux = 'les_deux';

    public function getLabel(): string
    {
        return match ($this) {
            self::LivraisonDeBiens => 'Livraison de biens',
            self::PrestationDeServices => 'Prestation de services',
            self::LesDeux => 'Livraison de biens et prestation de services',
        };
    }

    /**
     * @param  iterable<NatureLigne>  $natures
     */
    public static function depuisNatures(iterable $natures): self
    {
        $presentes = collect($natures)->unique(fn (NatureLigne $nature): string => $nature->value);

        return match (true) {
            $presentes->count() > 1 => self::LesDeux,
            $presentes->first() === NatureLigne::Service => self::PrestationDeServices,
            default => self::LivraisonDeBiens,
        };
    }
}
