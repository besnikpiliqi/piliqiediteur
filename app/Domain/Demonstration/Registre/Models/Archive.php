<?php

namespace App\Domain\Demonstration\Registre\Models;

use App\Domain\Calendrier\Casts\Instant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * L'archive de la caisse d'une copie, faite avant sa suppression (lot
 * DEMO-COPIE, C.18), gardée six ans. Écrite par demo_admin seul.
 *
 * @property int $id
 * @property string $reference
 * @property string $motif
 * @property string $chemin
 * @property string $empreinte
 * @property array<string, mixed> $sommaire
 * @property string $signature
 * @property CarbonImmutable $creee_le
 * @property CarbonImmutable $a_effacer_le
 */
class Archive extends Model
{
    protected $connection = 'registre';

    protected $table = 'archives';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sommaire' => 'array',
            'creee_le' => Instant::class,
            'a_effacer_le' => Instant::class,
        ];
    }
}
