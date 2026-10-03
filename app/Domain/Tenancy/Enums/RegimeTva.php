<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Détermine la cadence de transmission de l'e-reporting (CGI art. 286 I
 * 3° bis / annexe II art. 242 nonies B), PAS la cadence de la déclaration
 * de TVA elle-même : les deux sont des obligations distinctes qui peuvent
 * suivre des rythmes différents. Voir CalculateurPeriodeEReporting pour le
 * détail des périodes et échéances propres à chaque régime.
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

    public function getLabel(): string
    {
        return match ($this) {
            self::ReelNormalMensuel => 'Réel normal (déclaration mensuelle)',
            self::ReelNormalTrimestriel => 'Réel normal (déclaration trimestrielle)',
            self::ReelSimplifie => 'Réel simplifié',
            self::FranchiseEnBase => 'Franchise en base de TVA',
        };
    }
}
