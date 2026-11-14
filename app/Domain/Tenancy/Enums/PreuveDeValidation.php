<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * La preuve qui valide une société (voir ValiderLaSocieteAction), inscrite
 * au journal d'audit avec la validation : le code du courrier recommandé
 * envoyé au nom de son représentant légal, à l'adresse officielle de son
 * siège, pour une société qui s'inscrit elle-même ; l'invitation d'un
 * cabinet d'expertise comptable vérifié, acceptée par la personne qui en a
 * reçu le lien, l'entreprise contrôlée active au répertoire Sirene, pour
 * le client qu'il invite (voir AccepterLInvitationAction). Une autre voie
 * que l'éditeur déciderait s'y ajouterait, sans changer le reste de la
 * validation.
 */
enum PreuveDeValidation: string implements HasLabel
{
    case CodeDuCourrier = 'code_du_courrier';
    case InvitationDuCabinet = 'invitation_cabinet';

    public function getLabel(): string
    {
        return match ($this) {
            self::CodeDuCourrier => 'Code du courrier recommandé',
            self::InvitationDuCabinet => 'Invitation du cabinet',
        };
    }
}
