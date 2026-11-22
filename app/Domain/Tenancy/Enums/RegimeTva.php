<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Le régime de TVA de la société, commun à tous ses magasins, choisi dans
 * ses réglages fiscaux (voir ModifierParametresFiscauxSocieteAction) :
 * tant qu'il ne l'est pas, aucune opération commerciale réelle (voir
 * Magasin::refusDeVente()), et, une fois choisi, il ne redevient jamais
 * vide.
 *
 * Le régime simplifié (déclaration annuelle CA12 et ses deux acomptes)
 * est supprimé à compter du 1er janvier 2027 (loi n° 2025-127 du
 * 14/02/2025, art. 38) : ses redevables passent d'office au réel normal,
 * et PILIQI, qui ne sera pas en production avant 2027, ne le propose pas
 * (décision de Claude, D76). Restent :
 * - le réel normal, déclaration trimestrielle (CA3), quand le chiffre
 *   d'affaires, majoré des acquisitions taxables, ne dépasse pas
 *   1 000 000 € l'année précédente et 1 100 000 € l'année en cours (voir
 *   SEUIL_DU_TRIMESTRIEL_EN_CENTIMES) ;
 * - le réel normal, déclaration mensuelle (CA3), au-delà ou sur option ;
 * - la franchise en base, où le magasin vend sans TVA (voir
 *   estFranchise()) : ses ventes sont à 0 %, au même prix TTC, avec la
 *   mention MENTION_FRANCHISE (B10, voir ConstructeurLigneDocument et
 *   MentionsFiscales), et aucune TVA ne s'affiche dans PILIQI (décision
 *   de l'éditeur du 18/11/2026).
 *
 * Le régime donne aussi la cadence de l'e-reporting, que la plateforme
 * agréée tient d'après lui, à partir du 1er septembre 2027 : au réel
 * normal trimestriel, les données de transaction et de paiement une fois
 * par mois ; au réel normal mensuel, les transactions par décade et les
 * paiements une fois par mois (art. 290 et 290 A du CGI, art. 242 nonies
 * O et P de l'annexe II). PILIQI lui envoie chaque clôture journalière
 * ayant des opérations (voir EReportingDesParticuliers) ; c'est la
 * plateforme qui fait ce calendrier.
 */
enum RegimeTva: string implements HasLabel
{
    case ReelNormalMensuel = 'reel_normal_mensuel';
    case ReelNormalTrimestriel = 'reel_normal_trimestriel';
    case FranchiseEnBase = 'franchise_en_base';

    /**
     * L'article qui dispense de TVA un magasin en franchise : seul endroit
     * à changer quand la référence du code des impositions sur les biens
     * et services (CIBS) sera confirmée, l'ancienne restant tolérée un
     * temps.
     */
    public const ARTICLE_FRANCHISE = 'art. 293 B du CGI';

    /**
     * La mention qu'imprime toute vente en franchise.
     */
    public const MENTION_FRANCHISE = 'TVA non applicable, '.self::ARTICLE_FRANCHISE;

    /**
     * Le chiffre d'affaires de l'année en cours au-delà duquel la
     * déclaration de TVA devient mensuelle : 1 100 000,00 €, en centimes
     * (voir SeuilDuTrimestriel). PILIQI n'y compte que ce qu'il voit : les
     * ventes de ses magasins, nettes des avoirs, jamais les acquisitions
     * taxables ni les ventes faites hors de PILIQI.
     */
    public const SEUIL_DU_TRIMESTRIEL_EN_CENTIMES = 110_000_000;

    public function getLabel(): string
    {
        return match ($this) {
            self::ReelNormalMensuel => 'Réel normal, déclaration mensuelle (CA3)',
            self::ReelNormalTrimestriel => 'Réel normal, déclaration trimestrielle (CA3)',
            self::FranchiseEnBase => 'Franchise en base de TVA',
        };
    }

    public function estFranchise(): bool
    {
        return $this === self::FranchiseEnBase;
    }

    /**
     * Le rythme de ses déclarations de TVA (voir CadenceDeLaTva), celui du
     * report de son crédit ; null en franchise en base, qui n'en déclare
     * aucune.
     */
    public function cadence(): ?CadenceDeLaTva
    {
        return match ($this) {
            self::ReelNormalMensuel => CadenceDeLaTva::Mensuelle,
            self::ReelNormalTrimestriel => CadenceDeLaTva::Trimestrielle,
            self::FranchiseEnBase => null,
        };
    }
}
