<?php

namespace App\Filament\Concerns;

use Filament\Forms\Components\Hidden;
use Illuminate\Support\Str;

/**
 * Le jeton unique d'une demande (R3, voir ActionsUniques) : un champ caché
 * du formulaire ou de la fenêtre, qui reçoit un nouvel identifiant à
 * chaque ouverture et repart tel quel avec la demande. Renvoyée après une
 * réponse perdue, la même demande porte le même jeton, et l'action rend le
 * résultat déjà enregistré au lieu de recommencer ; une nouvelle ouverture,
 * après un succès, en porte un nouveau.
 */
trait PorteUnJeton
{
    public static function champDuJeton(): Hidden
    {
        return Hidden::make('jeton')
            // Aussi quand la fenêtre remplit son formulaire elle-même
            // (fillForm()), sans les valeurs par défaut de ses champs.
            ->afterStateHydrated(function (Hidden $champ, mixed $state): void {
                if (! is_string($state) || ! Str::isUuid($state)) {
                    $champ->state((string) Str::uuid());
                }
            })
            ->rules(['required', 'uuid']);
    }
}
