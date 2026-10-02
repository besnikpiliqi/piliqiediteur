<?php

namespace App\Domain\Facturation\Models;

use App\Domain\Contacts\Models\Client;
use App\Domain\Facturation\Enums\StatutDevis;
use App\Domain\Tenancy\Concerns\BelongsToMagasin;
use Database\Factories\DevisFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Devis extends Model
{
    /** @use HasFactory<DevisFactory> */
    use BelongsToMagasin, HasFactory, SoftDeletes;

    protected $table = 'devis';

    // numero, statut, date_emission et les totaux sont volontairement
    // absents : ils ne sont écrits que par CreerDevisAction/EmettreDevisAction,
    // jamais par mass assignment depuis une requête utilisateur.
    protected $fillable = [
        'client_id',
        'date_validite',
        'devise',
        'conditions_paiement',
        'notes',
    ];

    protected $casts = [
        'statut' => StatutDevis::class,
        'date_emission' => 'datetime',
        'date_validite' => 'date',
        'total_ht' => 'decimal:2',
        'total_tva' => 'decimal:2',
        'total_ttc' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function (Devis $devis) {
            $devis->statut ??= StatutDevis::Brouillon;
            $devis->devise ??= 'EUR';
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function lignes(): HasMany
    {
        return $this->hasMany(LigneDevis::class)->orderBy('ordre');
    }

    public function factures(): HasMany
    {
        return $this->hasMany(Facture::class);
    }

    public function estModifiable(): bool
    {
        return $this->statut === StatutDevis::Brouillon;
    }
}
