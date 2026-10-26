<?php

namespace App\Domain\Tenancy\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * La preuve qui valide une société (voir ValiderLaSocieteAction), inscrite
 * au journal d'audit avec la validation : aujourd'hui seulement le code du
 * courrier recommandé envoyé au nom de son représentant légal, à
 * l'adresse officielle de son siège. Une autre voie que l'éditeur
 * déciderait s'y ajouterait, sans changer le reste de la validation.
 */
enum PreuveDeValidation: string implements HasLabel
{
    case CodeDuCourrier = 'code_du_courrier';

    public function getLabel(): string
    {
        return match ($this) {
            self::CodeDuCourrier => 'Code du courrier recommandé',
        };
    }
}
