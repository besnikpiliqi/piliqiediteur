<?php

namespace App\Domain\Charges\Services;

use App\Domain\Calendrier\Calendrier;
use App\Domain\Charges\Enums\NatureDeCharge;
use App\Domain\Charges\Enums\TypeDeCharge;

/**
 * Ce que PILIQI propose pour la TVA d'une charge (décisions de Claude,
 * D76, écrites dans l'onglet « À faire vérifier par un professionnel » ;
 * le comptable corrige toujours l'une et l'autre) :
 * - la TVA déductible de chaque ligne : toute sa TVA, sauf pour un
 *   véhicule de tourisme (achat, location, entretien), l'hébergement du
 *   dirigeant ou du personnel et le transport de personnes, 0 € (TVA non
 *   déductible), pour les cadeaux, 0 € (déductibles seulement jusqu'à
 *   73 € TTC par bénéficiaire et par an), et pour le carburant, 80 % de
 *   la TVA d'un véhicule de tourisme, 100 % d'un véhicule utilitaire ;
 *   chaque proposition qui n'est pas toute la TVA dit pourquoi, par une
 *   phrase grise (voir raisonDeLaDeduction()) ;
 * - le mois de TVA : celui de la date de la pièce pour des biens ; pour
 *   des services, celui de la date de paiement si elle est saisie, sinon
 *   celui de la date de la pièce, « à confirmer au paiement », car la TVA
 *   d'une prestation de services se déduit en principe au paiement, sauf
 *   si le fournisseur a opté pour les débits.
 * Les montants sont en centimes, arrondis au centime le plus proche, un
 * demi-centime au centime supérieur.
 */
final class PropositionsDeTva
{
    public const CADEAUX = 'Déductible seulement pour un cadeau de 73 € TTC au plus par bénéficiaire et par an : à corriger par le comptable si c\'est le cas.';

    public const A_CONFIRMER_AU_PAIEMENT = 'À confirmer au paiement : la TVA d\'une prestation de services se déduit en principe au mois de son paiement, sauf si le fournisseur a opté pour les débits.';

    /**
     * La part de la TVA proposée à la déduction, en pour cent.
     */
    public static function pourcentageDeduit(?TypeDeCharge $type, bool $vehiculeUtilitaire): int
    {
        return match ($type) {
            TypeDeCharge::VehiculeDeTourisme, TypeDeCharge::Hebergement, TypeDeCharge::TransportDePersonnes, TypeDeCharge::Cadeaux => 0,
            TypeDeCharge::Carburant => $vehiculeUtilitaire ? 100 : 80,
            default => 100,
        };
    }

    /**
     * La TVA déductible proposée pour une ligne de cette TVA.
     */
    public static function tvaDeductible(?TypeDeCharge $type, bool $vehiculeUtilitaire, int $tva): int
    {
        $pourcentage = self::pourcentageDeduit($type, $vehiculeUtilitaire);

        return $pourcentage === 100 ? $tva : intdiv($tva * $pourcentage * 2 + 100, 200);
    }

    /**
     * La phrase grise qui dit pourquoi la TVA proposée à la déduction n'est
     * pas toute la TVA ; null quand elle l'est.
     */
    public static function raisonDeLaDeduction(?TypeDeCharge $type, bool $vehiculeUtilitaire): ?string
    {
        return match ($type) {
            TypeDeCharge::VehiculeDeTourisme => 'TVA non déductible : véhicule de tourisme (achat, location, entretien).',
            TypeDeCharge::Hebergement => 'TVA non déductible : hébergement du dirigeant ou du personnel.',
            TypeDeCharge::TransportDePersonnes => 'TVA non déductible : transport de personnes.',
            TypeDeCharge::Cadeaux => self::CADEAUX,
            TypeDeCharge::Carburant => $vehiculeUtilitaire ? null : 'Carburant d\'un véhicule de tourisme : 80 % de la TVA se déduisent (100 % pour un véhicule utilitaire).',
            default => null,
        };
    }

    /**
     * Le mois de TVA proposé, « AAAA-MM », pour une charge de cette nature
     * datée de ces jours « AAAA-MM-JJ ».
     */
    public static function moisDeTva(NatureDeCharge $nature, string $datePiece, ?string $datePaiement): string
    {
        $jour = $nature === NatureDeCharge::Services && $datePaiement !== null ? $datePaiement : $datePiece;

        return substr(Calendrier::jourValide($jour) ?? $datePiece, 0, 7);
    }

    /**
     * Le mois proposé est-il à confirmer au paiement : des services dont la
     * date de paiement n'est pas encore saisie ?
     */
    public static function aConfirmerAuPaiement(NatureDeCharge $nature, ?string $datePaiement): bool
    {
        return $nature === NatureDeCharge::Services && $datePaiement === null;
    }
}
