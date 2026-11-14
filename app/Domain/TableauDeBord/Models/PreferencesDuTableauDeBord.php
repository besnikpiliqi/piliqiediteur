<?php

namespace App\Domain\TableauDeBord\Models;

use App\Domain\TableauDeBord\Enums\EcranDuTableauDeBord;
use App\Domain\Tenancy\Concerns\BelongsToSociete;
use Illuminate\Database\Eloquent\Model;

/**
 * Les préférences d'un compte sur un écran du tableau de bord (voir
 * PreferencesDesGraphiques, qui seul les lit et les écrit) : une ligne par
 * compte et par écran, dans la société du compte, ses réglages en JSON
 * (voir ReglagesDuTableauDeBord), jamais lus sans être vérifiés.
 *
 * @property int $user_id
 * @property EcranDuTableauDeBord $ecran
 * @property array<array-key, mixed> $reglages
 */
class PreferencesDuTableauDeBord extends Model
{
    use BelongsToSociete;

    protected $table = 'preferences_tableau_de_bord';

    protected $fillable = [];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'ecran' => EcranDuTableauDeBord::class,
            'reglages' => 'array',
        ];
    }
}
