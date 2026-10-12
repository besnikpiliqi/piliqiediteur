<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Le statut d'un compte dans sa société (voir ChangerStatutCompteAction) :
 * - actif ;
 * - suspendu : il n'ouvre plus aucun panneau et n'a plus aucun droit, dès
 *   la requête suivante (voir User::canAccessPanel() et
 *   ControleDesDroits), mais garde ses affectations pour sa réintégration ;
 * - retiré : il a quitté la société, sans affectation, sans autorisation
 *   et sans notification ; le compte ne s'efface pas et garde son adresse,
 *   qui ne sert jamais à un autre compte, et la personne peut, avec son
 *   accord, rejoindre une autre société ou créer la sienne avec lui (voir
 *   AccepterUnRattachementAction et CreerSaSocieteAvecUnCompteRetireAction).
 */
enum StatutCompte: string implements HasColor, HasLabel
{
    case Actif = 'actif';
    case Suspendu = 'suspendu';
    case Retire = 'retire';

    public function getLabel(): string
    {
        return match ($this) {
            self::Actif => 'Actif',
            self::Suspendu => 'Suspendu',
            self::Retire => 'Retiré',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Actif => 'success',
            self::Suspendu => 'warning',
            self::Retire => 'danger',
        };
    }
}
