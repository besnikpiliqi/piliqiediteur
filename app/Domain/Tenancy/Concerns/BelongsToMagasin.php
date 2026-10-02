<?php

namespace App\Domain\Tenancy\Concerns;

use App\Domain\Tenancy\Models\Magasin;
use App\Domain\Tenancy\Scopes\MagasinScope;
use App\Domain\Tenancy\Services\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * À appliquer sur tout modèle appartenant à un magasin (Produit, Client,
 * Fournisseur, Facture, ...).
 *
 * Deux garanties, jamais une seule :
 *  1. Lecture : MagasinScope filtre automatiquement toute requête.
 *  2. Écriture : magasin_id est TOUJOURS écrasé par le TenantContext actif
 *     à la création, quelle que soit la valeur envoyée par le payload. On
 *     ne fait jamais confiance à un magasin_id fourni par le client : sans
 *     ça, un payload malveillant pourrait rattacher un enregistrement à un
 *     autre magasin via mass assignment.
 *
 * magasin_id ne doit JAMAIS figurer dans $fillable des modèles qui
 * utilisent ce trait.
 */
trait BelongsToMagasin
{
    public static function bootBelongsToMagasin(): void
    {
        static::addGlobalScope(new MagasinScope);

        static::creating(function ($model) {
            $model->magasin_id = app(TenantContext::class)->id();
        });
    }

    public function magasin(): BelongsTo
    {
        return $this->belongsTo(Magasin::class);
    }
}
