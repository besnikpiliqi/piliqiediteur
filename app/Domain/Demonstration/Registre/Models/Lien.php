<?php

namespace App\Domain\Demonstration\Registre\Models;

use App\Domain\Calendrier\Casts\Instant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Le lien d'une copie (lot DEMO-COPIE, C.1 et C.4) : seulement l'empreinte
 * SHA-256 de son secret.
 *
 * @property int $id
 * @property int $copie_id
 * @property string $empreinte
 * @property int $generation
 * @property CarbonImmutable $fin
 * @property CarbonImmutable $cree_le
 * @property CarbonImmutable|null $retire_le
 */
class Lien extends Model
{
    protected $connection = 'registre';

    protected $table = 'liens';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'generation' => 'integer',
            'fin' => Instant::class,
            'cree_le' => Instant::class,
            'retire_le' => Instant::class,
        ];
    }

    /**
     * @return BelongsTo<Copie, $this>
     */
    public function copie(): BelongsTo
    {
        return $this->belongsTo(Copie::class);
    }
}
