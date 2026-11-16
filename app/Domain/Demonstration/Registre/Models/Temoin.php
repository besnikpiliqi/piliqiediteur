<?php

namespace App\Domain\Demonstration\Registre\Models;

use App\Domain\Calendrier\Casts\Instant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Le témoin d'une visite d'une copie (lot DEMO-COPIE, C.1, C.4 et C.5) :
 * seulement l'empreinte SHA-256 de sa valeur. Le rôle des pages l'écrit.
 *
 * @property int $id
 * @property string $empreinte
 * @property int $copie_id
 * @property int $generation_du_lien
 * @property string $entree
 * @property CarbonImmutable $cree_le
 * @property CarbonImmutable $fin
 * @property CarbonImmutable|null $vu_le
 * @property CarbonImmutable|null $retire_le
 * @property int|null $compte_choisi
 */
class Temoin extends Model
{
    protected $connection = 'registre';

    protected $table = 'temoins';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'generation_du_lien' => 'integer',
            'cree_le' => Instant::class,
            'fin' => Instant::class,
            'vu_le' => Instant::class,
            'retire_le' => Instant::class,
            'compte_choisi' => 'integer',
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
