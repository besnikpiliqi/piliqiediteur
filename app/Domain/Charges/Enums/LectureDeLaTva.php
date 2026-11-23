<?php

namespace App\Domain\Charges\Enums;

use App\Domain\TableauDeBord\Support\Perimetre;

/**
 * Ce qu'un compte voit de la TVA de la société sur un écran (lot TVA27),
 * qui dit ce que le message du crédit de TVA lui montre (voir
 * MessagesDuCreditDeTva::message()) :
 * - aucune : il ne voit pas la carte de la TVA, et le message ne lui dit
 *   rien du crédit ;
 * - sans le report : il voit la carte de la TVA, mais pas sur toute la
 *   société (un magasin ou les charges de « Toute la société » lui
 *   manquent) : le message lui dit où en est le crédit, sans son montant ;
 * - avec le report : il voit la TVA de toute la société avec le report du
 *   crédit (voir Perimetre::reporteLeCreditDeTva()), et le message lui en
 *   dit le montant.
 */
enum LectureDeLaTva
{
    case Aucune;
    case SansLeReport;
    case AvecLeReport;

    /**
     * Ce que ce périmètre des graphiques montre de la TVA ; aucune pour
     * qui n'en voit aucun.
     */
    public static function du(?Perimetre $perimetre): self
    {
        return match (true) {
            $perimetre === null => self::Aucune,
            $perimetre->reporteLeCreditDeTva() => self::AvecLeReport,
            $perimetre->montreLaCarteDeLaTva() => self::SansLeReport,
            default => self::Aucune,
        };
    }

    /**
     * Le message en dit-il le montant du crédit ?
     */
    public function voitLeMontant(): bool
    {
        return $this === self::AvecLeReport;
    }
}
