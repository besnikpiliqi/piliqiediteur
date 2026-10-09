<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Facturation\Contracts\DocumentEnvoyable;
use App\Domain\Facturation\Enums\NatureEnvoi;
use App\Domain\Facturation\Enums\StatutEnvoi;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use App\Models\User;
use Database\Factories\EnvoiDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Un e-mail parti (ou à partir) avec un devis, une facture ou un avoir en
 * pièce jointe : créé par EnvoyerDocumentAction, envoyé par
 * EnvoyerDocumentJob, qui en constate le résultat. Seule une relance en
 * échec en change ensuite : EnvoyerDocumentAction::relancer() la
 * reprogramme.
 *
 * @property-read DocumentEnvoyable&Model $document
 */
class EnvoiDocument extends Model
{
    /** @use HasFactory<EnvoiDocumentFactory> */
    use BelongsToMagasin, HasFactory;

    protected $table = 'envois_documents';

    // statut, erreur et envoye_le : jamais en mass assignment, uniquement
    // par EnvoyerDocumentJob, qui les constate (et par la reprogrammation
    // d'une relance en échec).
    protected $fillable = [
        'destinataire',
        'objet',
        'message',
        'nature',
        'niveau_relance',
        'envoye_par',
    ];

    protected $casts = [
        'nature' => NatureEnvoi::class,
        'statut' => StatutEnvoi::class,
        'niveau_relance' => 'integer',
        'envoye_le' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (EnvoiDocument $envoi) {
            $envoi->statut ??= StatutEnvoi::EnAttente;
        });
    }

    public function document(): MorphTo
    {
        return $this->morphTo();
    }

    public function envoyePar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'envoye_par');
    }
}
