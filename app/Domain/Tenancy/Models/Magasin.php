<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Enums\StatutMagasin;
use App\Models\User;
use Database\Factories\MagasinFactory;
use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Le tenant. Volontairement PAS de BelongsToMagasin ici : un magasin ne
 * s'appartient pas à lui-même, et ce modèle doit rester accessible sans
 * contexte tenant résolu (ex: l'écran de sélection du magasin actif).
 */
class Magasin extends Model implements HasName
{
    /** @use HasFactory<MagasinFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'nom',
        'raison_sociale',
        'siren',
        'siret',
        'numero_tva',
        'adresse',
        'complement_adresse',
        'code_postal',
        'ville',
        'pays',
        'email',
        'telephone',
        'devise',
        'locale',
        'statut',
        'heure_cloture_automatique',
    ];

    protected $casts = [
        'statut' => StatutMagasin::class,
        'heure_cloture_automatique' => 'datetime:H:i',
    ];

    protected static function booted(): void
    {
        static::creating(function (Magasin $magasin) {
            $magasin->uuid ??= (string) Str::uuid();
            $magasin->statut ??= StatutMagasin::EnAttenteVerification;
            $magasin->devise ??= 'EUR';
            $magasin->locale ??= 'fr';
            $magasin->pays ??= 'FR';
            $magasin->heure_cloture_automatique ??= '00:00';
        });
    }

    public function utilisateurs(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'magasin_user')
            ->using(MagasinUser::class)
            ->withPivot(['role', 'statut', 'invited_by', 'joined_at'])
            ->withTimestamps();
    }

    public function estActif(): bool
    {
        return $this->statut === StatutMagasin::Active;
    }

    public function getFilamentName(): string
    {
        return $this->nom;
    }
}
