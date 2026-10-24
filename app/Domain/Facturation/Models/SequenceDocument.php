<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Facturation\Enums\TypeDocument;
use Illuminate\Database\Eloquent\Model;

/**
 * Pas de BelongsToMagasin ici volontairement : cette table est une
 * infrastructure interne pilotée exclusivement par
 * DocumentNumberingService, avec ses propres requêtes explicites
 * (lockForUpdate) où le filtrage automatique par scope global ajouterait
 * de l'implicite là où la correction anti race-condition exige l'inverse.
 */
class SequenceDocument extends Model
{
    protected $table = 'sequences_documents';

    protected $fillable = [
        'magasin_id',
        'type_document',
        'period_key',
        'prefixe',
        'prochain_numero',
        'rembourrage',
    ];

    protected $casts = [
        'type_document' => TypeDocument::class,
    ];
}
