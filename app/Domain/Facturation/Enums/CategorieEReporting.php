<?php

namespace App\Domain\Facturation\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * La catégorie sous laquelle l'e-reporting déclare chaque jour les ventes
 * aux particuliers (art. 290 du CGI) : les quatre que reçoit la plateforme
 * agréée, d'après l'annexe A de la norme AFNOR XP Z12-012 (spécification
 * de SUPER PDP 1.36.0.beta, schéma b2c_transaction). La valeur est le code
 * de la norme, que l'envoi de l'étape 2 transmettra ; l'écran n'en montre
 * jamais que le libellé (voir EReportingDesParticuliers).
 *
 * Aucune colonne ne garde la catégorie d'une vente avant l'étape 2 : elle
 * se déduit, à chaque lecture, de la nature de chaque ligne et de la
 * nature d'opération du document, enregistrées à son émission. Le régime
 * de la marge attend l'étape 14 (achats auprès de particuliers).
 */
enum CategorieEReporting: string implements HasLabel
{
    case LivraisonsDeBiens = 'TLB1';
    case PrestationsDeServices = 'TPS1';
    case NonSoumisesALaTvaEnFrance = 'TNT1';
    case RegimeDeLaMarge = 'TMA1';

    public function getLabel(): string
    {
        return match ($this) {
            self::LivraisonsDeBiens => 'Livraisons de biens',
            self::PrestationsDeServices => 'Prestations de services',
            self::NonSoumisesALaTvaEnFrance => 'Opérations non soumises à la TVA en France',
            self::RegimeDeLaMarge => 'Régime de la marge',
        };
    }

    /**
     * La catégorie d'une ligne vendue avec la TVA française : livraison
     * d'un bien ou prestation d'un service.
     */
    public static function deLaNature(NatureLigne $nature): self
    {
        return match ($nature) {
            NatureLigne::Bien => self::LivraisonsDeBiens,
            NatureLigne::Service => self::PrestationsDeServices,
        };
    }
}
