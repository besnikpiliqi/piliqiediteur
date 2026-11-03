<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Le régime de TVA du magasin.
 *
 * Il détermine la cadence de transmission de l'e-reporting (art. 290 et
 * 290 A du CGI, art. 242 nonies O et P de l'annexe II), PAS la cadence de
 * la déclaration de TVA elle-même : les deux sont des obligations
 * distinctes qui peuvent suivre des rythmes différents. C'est la
 * plateforme agréée qui tient ce calendrier, d'après le régime de la
 * société : PILIQI lui enverra chaque clôture journalière ayant des
 * opérations (voir EReportingDesParticuliers).
 *
 * En franchise en base, le magasin vend sans TVA (voir estFranchise()) :
 * ses ventes sont à 0 %, au même prix TTC, avec la mention
 * MENTION_FRANCHISE (B10, voir ConstructeurLigneDocument et
 * MentionsFiscales).
 *
 * Le régime "réel simplifié" est amené à disparaître (loi n°2025-127,
 * art. 38, à compter du 1er janvier 2027, migration automatique vers le
 * réel normal) : conservé ici tel quel, à retirer le moment venu.
 */
enum RegimeTva: string implements HasLabel
{
    case ReelNormalMensuel = 'reel_normal_mensuel';
    case ReelNormalTrimestriel = 'reel_normal_trimestriel';
    case ReelSimplifie = 'reel_simplifie';
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

    public function getLabel(): string
    {
        return match ($this) {
            self::ReelNormalMensuel => 'Réel normal (déclaration mensuelle)',
            self::ReelNormalTrimestriel => 'Réel normal (déclaration trimestrielle)',
            self::ReelSimplifie => 'Réel simplifié',
            self::FranchiseEnBase => 'Franchise en base de TVA',
        };
    }

    public function estFranchise(): bool
    {
        return $this === self::FranchiseEnBase;
    }
}
