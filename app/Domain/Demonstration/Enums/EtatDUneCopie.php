<?php

namespace App\Domain\Demonstration\Enums;

/**
 * Les états d'une copie au registre du site de démonstration (lot
 * DEMO-COPIE, C.1). Seules une copie prête et une copie en lecture seule
 * (au-delà de sa place, C.16) s'ouvrent ; les autres montrent leur page
 * d'attente ou « Cette démonstration est terminée ».
 */
enum EtatDUneCopie: string
{
    case EnPreparation = 'en_preparation';
    case EnAttenteDUnePlace = 'en_attente_d_une_place';
    case Prete = 'prete';
    case EnEchec = 'en_echec';
    case ARefaire = 'a_refaire';
    case EnMigration = 'en_migration';
    case EnLectureSeule = 'en_lecture_seule';
    case EnArchivage = 'en_archivage';
    case EnSuppression = 'en_suppression';
    case Supprimee = 'supprimee';
    case Perdue = 'perdue';

    public function seSert(): bool
    {
        return in_array($this, [self::Prete, self::EnLectureSeule], true);
    }

    /**
     * Une copie vivante occupe une place du plafond.
     */
    public function occupeUnePlace(): bool
    {
        return ! in_array($this, [self::EnAttenteDUnePlace, self::EnEchec, self::Supprimee, self::EnSuppression], true);
    }

    /**
     * L'état que lit le site réel (ReponseDuSiteDeDemonstration::ETATS_LUS).
     */
    public function pourLeSiteReel(): string
    {
        return match ($this) {
            self::Prete, self::EnLectureSeule, self::EnMigration => 'prete',
            self::EnAttenteDUnePlace => 'en_attente_d_une_place',
            self::EnEchec => 'en_echec',
            self::Supprimee, self::EnSuppression, self::EnArchivage => 'supprimee',
            self::EnPreparation, self::ARefaire, self::Perdue => 'en_preparation',
        };
    }
}
