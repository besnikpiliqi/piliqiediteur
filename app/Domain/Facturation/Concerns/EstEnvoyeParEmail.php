<?php

namespace App\Domain\Facturation\Concerns;

use App\Domain\Facturation\Models\EnvoiDocument;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * L'historique d'envois d'un DocumentEnvoyable (voir EnvoiDocument).
 */
trait EstEnvoyeParEmail
{
    public function envois(): MorphMany
    {
        return $this->morphMany(EnvoiDocument::class, 'document');
    }
}
