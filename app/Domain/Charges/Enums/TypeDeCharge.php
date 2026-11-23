<?php

namespace App\Domain\Charges\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * La liste des types de charge fournie par PILIQI (décision de l'éditeur
 * du 14/11/2026, lot CHARGES), dans son ordre ; la société la complète de
 * ses propres types (voir TypeDeChargeDeLaSociete). Chaque type dit s'il
 * paie des biens ou des services, ce qui fixe le mois de TVA proposé, et
 * quelle part de sa TVA PILIQI propose de déduire (décisions de Claude,
 * D76, voir PropositionsDeTva) ; le comptable corrige l'une et l'autre.
 */
enum TypeDeCharge: string implements HasLabel
{
    case Loyer = 'loyer';
    case Electricite = 'electricite';
    case Gaz = 'gaz';
    case Eau = 'eau';
    case TelephoneEtInternet = 'telephone_internet';
    case Assurances = 'assurances';
    case Honoraires = 'honoraires';
    case EntretienEtReparations = 'entretien_reparations';
    case Fournitures = 'fournitures';
    case Carburant = 'carburant';
    case VehiculeDeTourisme = 'vehicule_tourisme';
    case Hebergement = 'hebergement';
    case RepasEtReceptions = 'repas_receptions';
    case Cadeaux = 'cadeaux';
    case TransportDePersonnes = 'transport_personnes';
    case FraisBancaires = 'frais_bancaires';
    case Publicite = 'publicite';
    case TransportDeMarchandises = 'transport_marchandises';
    case SousTraitance = 'sous_traitance';
    case LocationDeMateriel = 'location_materiel';
    case Formation = 'formation';
    case AbonnementsEtLogiciels = 'abonnements_logiciels';
    case Autres = 'autres';

    /**
     * Les types cochés « habituels » d'une société qui n'en a pas choisi
     * d'autres : ils gardent une ligne au tableau des charges même sans
     * charge de l'année.
     */
    public const HABITUELS_PAR_DEFAUT = [self::Loyer, self::Electricite];

    public function getLabel(): string
    {
        return match ($this) {
            self::Loyer => 'Loyer',
            self::Electricite => 'Électricité',
            self::Gaz => 'Gaz',
            self::Eau => 'Eau',
            self::TelephoneEtInternet => 'Téléphone et internet',
            self::Assurances => 'Assurances',
            self::Honoraires => 'Honoraires',
            self::EntretienEtReparations => 'Entretien et réparations',
            self::Fournitures => 'Fournitures',
            self::Carburant => 'Carburant',
            self::VehiculeDeTourisme => 'Véhicule de tourisme (achat, location, entretien)',
            self::Hebergement => 'Hébergement',
            self::RepasEtReceptions => 'Repas et réceptions',
            self::Cadeaux => 'Cadeaux',
            self::TransportDePersonnes => 'Transport de personnes',
            self::FraisBancaires => 'Frais bancaires',
            self::Publicite => 'Publicité',
            self::TransportDeMarchandises => 'Transport de marchandises',
            self::SousTraitance => 'Sous-traitance',
            self::LocationDeMateriel => 'Location de matériel',
            self::Formation => 'Formation',
            self::AbonnementsEtLogiciels => 'Abonnements et logiciels',
            self::Autres => 'Autres',
        };
    }

    /**
     * Des biens (fournitures, carburant, véhicule, cadeaux, eau) ou des
     * services (loyer, énergie livrée par réseau, téléphone, honoraires,
     * entretien…), décision de Claude, D76 : l'électricité et le gaz,
     * dont la TVA devient exigible à l'encaissement (CGI art. 269, 2-a),
     * suivent le paiement comme un service.
     */
    public function nature(): NatureDeCharge
    {
        return match ($this) {
            self::Eau, self::Fournitures, self::Carburant, self::VehiculeDeTourisme, self::Cadeaux => NatureDeCharge::Biens,
            default => NatureDeCharge::Services,
        };
    }
}
